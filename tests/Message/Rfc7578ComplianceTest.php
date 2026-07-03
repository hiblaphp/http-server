<?php

declare(strict_types=1);

namespace Tests\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\UploadedFile;

use function Hibla\await;

afterEach(function () {
    Loop::reset();

    $files = glob(sys_get_temp_dir() . '/hibla_up_*');

    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});

describe('RFC 7578 Compliance', function () {

    it('collects multiple files under one shared "name" parameter without [] per RFC 7578 section 4.3', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"documents\"; filename=\"doc1.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "content_of_doc1\r\n";

        $payload .= "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"documents\"; filename=\"doc2.txt\"\r\n" .
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

        expect($files)->toBeArray()
            ->and($files)->toHaveCount(2)
        ;
    });

    it('does not coalesce duplicate field names per RFC 7578 section 5.2', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"color\"\r\n\r\n" .
            "red\r\n" .
            "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"color\"\r\n\r\n" .
            "blue\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->all())->toHaveKey('color');

        $all = $form->all();
        expect($all['color'])->toBeArray()
            ->and($all['color'])->toBe(['red', 'blue'])
        ;
    });

    it('never honors the filename* extended parameter per RFC 7578 section 4.2 NOTE', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"avatar\"; filename*=UTF-8''caf%C3%A9.png\r\n" .
            "Content-Type: image/png\r\n\r\n" .
            "binarydata\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());
        $file = $form->getFile('avatar');

        if ($file instanceof UploadedFile) {
            expect($file->clientFilename)->not->toBe('café.png');
        } else {
            expect($file)->toBeNull();
        }
    });

    it('strips directory path information from supplied filenames per RFC 7578 section 4.2', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"upload\"; filename=\"../../etc/evil.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "payload\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());
        $file = $form->getFile('upload');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->clientFilename)->not->toContain('/')
            ->and($file->clientFilename)->not->toContain('\\')
            ->and($file->clientFilename)->toBe('evil.txt')
        ;
    });

    it('ignores non-standard Content- header fields per RFC 7578 section 4.8', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"note\"\r\n" .
            "Content-MD5: 1B2M2Y8AsgTpgAmY7PhCfg==\r\n" .
            "X-Custom-Header: should-be-ignored\r\n\r\n" .
            "hello\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->get('note'))->toBe('hello');
    });

    it('does not treat mid-content boundary-like substrings as a terminator per RFC 7578 section 4.1', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"note\"\r\n\r\n" .
            "see docs at --boundary123 for details\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->get('note'))->toBe('see docs at --boundary123 for details');
    });

    it('strips absolute path prefixes from filenames per RFC 7578 section 4.2 / RFC 2183 section 2.3', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"upload\"; filename=\"/etc/passwd\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "payload\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());
        $file = $form->getFile('upload');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->clientFilename)->not->toContain('/')
            ->and($file->clientFilename)->toBe('passwd')
        ;
    });

    it('ignores a part whose Content-Disposition omits the required "name" parameter per RFC 7578 section 4.2', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data\r\n\r\n" .
            "orphaned value\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->all())->not->toHaveKey('')
            ->and($form->all())->toBe([])
        ;
    });

    it('does not admit parts with a non-"form-data" disposition type per RFC 7578 section 4.2', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: attachment; name=\"note\"\r\n\r\n" .
            "should not be admitted\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->get('note'))->toBeNull();
    });

    it('preserves field ordering across distinct names per RFC 7578 section 5.2', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"first\"\r\n\r\n" .
            "1\r\n" .
            "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"second\"\r\n\r\n" .
            "2\r\n" .
            "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"third\"\r\n\r\n" .
            "3\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect(array_keys($form->all()))->toBe(['first', 'second', 'third']);
    });

    it('defaults an omitted part Content-Type to text/plain for file parts per RFC 7578 section 4.4', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"upload\"; filename=\"note.txt\"\r\n\r\n" .
            "plain text body\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());
        $file = $form->getFile('upload');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->clientMediaType)->toBe('text/plain');
    });

    it('parses a quoted boundary parameter from the Content-Type header per RFC 7578 section 4.1', function () {
        $boundary = 'AaB03x_quoted';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"field1\"\r\n\r\n" .
            "value1\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary="' . $boundary . '"'],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->get('field1'))->toBe('value1');
    });

    it('ignores a Content-Transfer-Encoding header rather than decoding the body per RFC 7578 section 4.7', function () {
        $boundary = 'boundary123';

        $payload = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"field1\"\r\n" .
            "Content-Type: text/plain;charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
            "Joe owes =E2=82=AC100.\r\n" .
            "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form->get('field1'))->toBe('Joe owes =E2=82=AC100.');
    });
});
