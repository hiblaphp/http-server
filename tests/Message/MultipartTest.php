<?php

declare(strict_types=1);

namespace Tests\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\MalformedMultipartException;
use Hibla\HttpServer\Message\MultipartForm;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\UploadedFile;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\ThroughStream;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();

    $files = glob(sys_get_temp_dir() . '/hibla_up_*');

    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});

describe('MultipartParser & Message Integration', function () {

    it('parses form fields and uploaded files successfully', function () {
        $boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
        $payload = createMultipartPayload($boundary, ['username' => 'john_doe', 'role' => 'admin'], [
            'avatar' => ['filename' => 'avatar.png', 'mime' => 'image/png', 'content' => 'fake_png_binary_data'],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form)->toBeInstanceOf(MultipartForm::class)
            ->and($form->get('username'))->toBe('john_doe')
            ->and($form->get('role'))->toBe('admin')
        ;

        $file = $form->getFile('avatar');
        expect($file)->toBeInstanceOf(UploadedFile::class);

        expect($file->clientFilename)->toBe('avatar.png')
            ->and($file->clientMediaType)->toBe('image/png')
            ->and($file->size)->toBe(20)
            ->and(file_exists($file->tmpPath))->toBeTrue()
        ;
    });

    it('asynchronously moves uploaded files and deletes temporary sources', function () {
        $boundary = 'boundary123';
        $payload = createMultipartPayload($boundary, [], [
            'document' => ['filename' => 'contract.pdf', 'mime' => 'application/pdf', 'content' => 'pdf_bytes_data'],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $file = $form->getFile('document');
        $tmpPath = $file->tmpPath;

        $destination = sys_get_temp_dir() . '/hibla_moved_contract.pdf';
        if (file_exists($destination)) {
            unlink($destination);
        }

        await($file->moveTo($destination));

        expect(file_exists($destination))->toBeTrue()
            ->and(file_get_contents($destination))->toBe('pdf_bytes_data')
            ->and(file_exists($tmpPath))->toBeFalse()
        ;

        if (file_exists($destination)) {
            unlink($destination);
        }
    });

    it('automatically unlinks temporary files when UploadedFile is garbage collected', function () {
        $boundary = 'boundary123';
        $payload = createMultipartPayload($boundary, [], [
            'file' => ['filename' => 'trash.txt', 'mime' => 'text/plain', 'content' => 'delete_me_soon'],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $file = $form->getFile('file');
        $tmpPath = $file->tmpPath;

        expect(file_exists($tmpPath))->toBeTrue();

        unset($file, $form);
        gc_collect_cycles();

        expect(file_exists($tmpPath))->toBeFalse();
    });

    it('correctly parses multiple uploaded files under an array key using [] syntax', function () {
        $boundary = 'boundary123';
        $payload = '';

        $payload .= "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"documents[]\"; filename=\"doc1.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "content_of_doc1\r\n";

        $payload .= "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"documents[]\"; filename=\"doc2.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "content_of_doc2\r\n";

        $payload .= "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $files = $form->getFiles('documents');

        expect($files)->toBeArray()->toHaveCount(2)
            ->and($files[0])->toBeInstanceOf(UploadedFile::class)
            ->and($files[1])->toBeInstanceOf(UploadedFile::class)
        ;

        [$f1, $f2] = $files;

        expect($f1->clientFilename)->toBe('doc1.txt')
            ->and($f2->clientFilename)->toBe('doc2.txt')
        ;
    });

    it('handles files that are uploaded with zero-byte content sizes', function () {
        $boundary = 'boundary123';
        $payload = createMultipartPayload($boundary, [], [
            'empty_file' => ['filename' => 'empty.txt', 'mime' => 'text/plain', 'content' => ''],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $file = $form->getFile('empty_file');

        expect($file)->toBeInstanceOf(UploadedFile::class)
            ->and($file->size)->toBe(0)
            ->and(file_exists($file->tmpPath))->toBeTrue()
            ->and(filesize($file->tmpPath))->toBe(0)
        ;
    });

    it('rejects parsing with a clear exception if Content-Type is missing the boundary', function () {
        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'application/json'],
            ''
        );

        expect(fn () => await($request->getParsedBody()))
            ->toThrow(\RuntimeException::class, 'Not a valid multipart/form-data request')
        ;
    });
});

describe('Multipart Advanced Cancellation Testing', function () {

    it('aborts active writes and instantly deletes partial temp files when request is cancelled mid-stream', function () {
        $boundary = 'boundary123';

        $chunk1 = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"avatar\"; filename=\"big_file.bin\"\r\n" .
            "Content-Type: application/octet-stream\r\n\r\n" .
            str_repeat('X', 1024 * 100);

        $bodyStream = new ThroughStream();

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $bodyStream
        );

        $parsePromise = $request->getParsedBody();

        $tempFilesBefore = glob(sys_get_temp_dir() . '/hibla_up_*');

        $bodyStream->write($chunk1);

        await(delay(0.01));

        $tempFilesAfter = glob(sys_get_temp_dir() . '/hibla_up_*');
        $newFiles = array_diff($tempFilesAfter, $tempFilesBefore);

        expect(\count($newFiles))->toBe(1);
        $partialTempFile = array_shift($newFiles);
        expect(file_exists($partialTempFile))->toBeTrue();

        $parsePromise->cancel();

        await(delay(0.01));

        expect(file_exists($partialTempFile))->toBeFalse();
        expect($parsePromise->isCancelled())->toBeTrue();
    });

    it('aborts async copying and deletes target files when moveTo() is cancelled mid-progress', function () {
        $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_move_cancel_');
        file_put_contents($tmpPath, str_repeat('Y', 5 * 1024 * 1024));

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'heavy.bin',
            'application/octet-stream',
            5 * 1024 * 1024
        );

        $destPath = sys_get_temp_dir() . '/hibla_interrupted_destination.bin';
        if (file_exists($destPath)) {
            unlink($destPath);
        }

        $movePromise = $uploadedFile->moveTo($destPath);

        Loop::runOnce();

        $movePromise->cancel();

        Loop::run();

        $existsAtEnd = file_exists($destPath);

        expect($existsAtEnd)->toBeFalse();
        expect(file_exists($tmpPath))->toBeTrue();
        expect($movePromise->isCancelled())->toBeTrue();

        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
    });
});

describe('Multipart Stream-Only Parsing (streamMultipart)', function () {

    it('streams multipart payloads on-the-fly and invokes callbacks with zero disk I/O', function () {
        $boundary = 'boundary123';
        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"field1\"\r\n\r\n" .
            "value1\r\n" .
            "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"file1\"; filename=\"test.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "file_content_123\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $fields = [];
        $files = [];

        await($request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, $fileStream) use (&$files): void {
                $contentPromise = new Promise(function ($resolve, $reject) use ($fileStream) {
                    $buffer = '';
                    $fileStream->on('data', function (string $chunk) use (&$buffer) {
                        $buffer .= $chunk;
                    });
                    $fileStream->on('end', function () use (&$buffer, $resolve) {
                        $resolve($buffer);
                    });
                    $fileStream->on('error', $reject);
                });

                $files[] = [
                    'name' => $name,
                    'filename' => $filename,
                    'mime' => $mime,
                    'content' => await($contentPromise),
                ];
            },
            onField: function (string $name, string $value) use (&$fields): void {
                $fields[$name] = $value;
            }
        ));

        expect($fields)->toBe(['field1' => 'value1'])
            ->and($files)->toHaveCount(1)
            ->and($files[0]['name'])->toBe('file1')
            ->and($files[0]['filename'])->toBe('test.txt')
            ->and($files[0]['mime'])->toBe('text/plain')
            ->and($files[0]['content'])->toBe('file_content_123')
        ;
    });

    it('streams multipart payloads on-the-fly and reads them cleanly via Promise API (readAllAsync)', function () {
        $boundary = 'boundary123';
        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"username\"\r\n\r\n" .
            "john_doe\r\n" .
            "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\n" .
            "Content-Type: image/png\r\n\r\n" .
            "binary_data_123\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $fields = [];
        $files = [];

        await($request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use (&$files): void {
                $files[] = [
                    'name' => $name,
                    'filename' => $filename,
                    'mime' => $mime,
                    'content' => await($fileStream->readAllAsync()),
                ];
            },
            onField: function (string $name, string $value) use (&$fields): void {
                $fields[$name] = $value;
            }
        ));

        expect($fields)->toBe(['username' => 'john_doe']);

        expect($files)->toHaveCount(1)
            ->and($files[0]['name'])->toBe('avatar')
            ->and($files[0]['filename'])->toBe('avatar.png')
            ->and($files[0]['mime'])->toBe('image/png')
            ->and($files[0]['content'])->toBe('binary_data_123')
        ;
    });

    it('allows reading file streams chunk-by-chunk via Promise API (readAsync)', function () {
        $boundary = 'boundary123';
        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"doc\"; filename=\"test.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "chunk1_chunk2_chunk3\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $chunks = [];

        await($request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use (&$chunks): void {
                while (($chunk = await($fileStream->readAsync(7))) !== null) {
                    $chunks[] = $chunk;
                }
            }
        ));

        expect($chunks)->toBe(['chunk1_', 'chunk2_', 'chunk3']);
    });

    it('allows piping file streams asynchronously to another destination via Promise API (pipeAsync)', function () {
        $boundary = 'boundary123';
        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"doc\"; filename=\"pipe.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "piped_content\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $destStream = new ThroughStream();
        $pipedContent = '';
        $destStream->on('data', function (string $chunk) use (&$pipedContent) {
            $pipedContent .= $chunk;
        });

        $bytesPiped = 0;

        await($request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use ($destStream, &$bytesPiped): void {
                $bytesPiped = await($fileStream->pipeAsync($destStream));
            }
        ));

        expect($pipedContent)->toBe('piped_content')
            ->and($bytesPiped)->toBe(13)
        ;
    });

    it('rejects streamMultipart with MalformedMultipartException if Content-Type lacks a boundary', function () {
        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data'],
            ''
        );

        expect(fn () => await($request->streamMultipart(fn () => null)))
            ->toThrow(MalformedMultipartException::class, 'Not a valid multipart/form-data request')
        ;
    });

    it('closes the parent request body stream and cancels all nested operations when streamMultipart promise is cancelled', function () {
        $boundary = 'boundary123';
        $bodyStream = new ThroughStream();

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $bodyStream
        );

        $fileEmitted = false;
        /** @var PromiseReadableStreamInterface|null $nestedFileStream */
        $nestedFileStream = null;

        $promise = $request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, $fileStream) use (&$fileEmitted, &$nestedFileStream): void {
                $fileEmitted = true;
                $nestedFileStream = $fileStream;
            }
        );

        $bodyStream->write("--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"avatar\"; filename=\"photo.png\"\r\n" .
            "Content-Type: image/png\r\n\r\n" .
            'partial_bytes_');

        await(delay(0.01));

        expect($fileEmitted)->toBeTrue()
            ->and($nestedFileStream)->not->toBeNull()
            ->and($nestedFileStream->isReadable())->toBeTrue()
            ->and($bodyStream->isReadable())->toBeTrue()
        ;

        $promise->cancel();

        await(delay(0.01));

        expect($promise->isCancelled())->toBeTrue()
            ->and($bodyStream->isReadable())->toBeFalse()
            ->and($nestedFileStream->isReadable())->toBeFalse()
        ;
    });

    it('allows cancelling a readAllAsync promise mid-stream without crashing the multipart parser', function () {
        $boundary = 'boundary123';
        $bodyStream = new ThroughStream();

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $bodyStream
        );

        $readPromise = null;
        $caughtException = null;

        $multipartPromise = $request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use (&$readPromise, &$caughtException): void {
                $readPromise = $fileStream->readAllAsync();

                try {
                    await($readPromise);
                } catch (CancelledException $e) {
                    $caughtException = $e;
                }
            }
        );

        $bodyStream->write("--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"doc\"; filename=\"test.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            'partial_chunk_');

        await(delay(0.01));

        expect($readPromise)->not->toBeNull();

        $readPromise->cancel();
        await(delay(0.01));

        $bodyStream->write("rest_of_chunk\r\n--{$boundary}--\r\n");
        $bodyStream->end();

        await($multipartPromise);

        expect($caughtException)->toBeInstanceOf(CancelledException::class);
    });

    it('allows cancelling a pipeAsync promise mid-stream without crashing the multipart parser', function () {
        $boundary = 'boundary123';
        $bodyStream = new ThroughStream();

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $bodyStream
        );

        $pipePromise = null;
        $caughtException = null;

        $destStream = new class () extends ThroughStream {
            public int $bytesHandled = 0;

            public string $buffer = '';

            public function write(string $data): bool
            {
                $this->buffer .= $data;
                $this->bytesHandled += \strlen($data);

                return parent::write($data);
            }
        };

        $multipartPromise = $request->streamMultipart(
            onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use (&$pipePromise, &$caughtException, $destStream): void {
                $pipePromise = $fileStream->pipeAsync($destStream);

                try {
                    await($pipePromise);
                } catch (CancelledException $e) {
                    $caughtException = $e;
                }
            }
        );

        $initialPayload = str_repeat('A', 1000);

        $bodyStream->write("--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"doc\"; filename=\"test.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            $initialPayload);

        await(delay(0.01));

        $expectedPreCancelBytes = 1000 - \strlen("\r\n--" . $boundary);

        expect($pipePromise)->not->toBeNull()
            ->and($destStream->bytesHandled)->toBe($expectedPreCancelBytes)
            ->and($destStream->buffer)->toBe(substr($initialPayload, 0, $expectedPreCancelBytes))
        ;

        $pipePromise->cancel();
        await(delay(0.01));

        $bodyStream->write("rest_of_chunk\r\n--{$boundary}--\r\n");
        $bodyStream->end();

        await($multipartPromise);

        expect($caughtException)->toBeInstanceOf(CancelledException::class)
            ->and($destStream->bytesHandled)->toBe($expectedPreCancelBytes)
            ->and($destStream->buffer)->toBe(substr($initialPayload, 0, $expectedPreCancelBytes))
            ->and($destStream->buffer)->not->toContain('rest_of_chunk')
        ;
    });
});
