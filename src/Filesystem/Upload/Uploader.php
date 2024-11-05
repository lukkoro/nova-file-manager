<?php

declare(strict_types=1);

namespace Oneduo\NovaFileManager\Filesystem\Upload;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Oneduo\NovaFileManager\Contracts\Filesystem\Upload\Uploader as UploaderContract;
use Oneduo\NovaFileManager\Events\FileUploaded;
use Oneduo\NovaFileManager\Events\FileUploading;
use Oneduo\NovaFileManager\Http\Requests\UploadFileRequest;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class Uploader implements UploaderContract
{
    /**
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\UploadFailedException
     */
    public function handle(UploadFileRequest $request, string $index = 'file'): array
    {
        if (!$request->validateUpload()) {
            throw ValidationException::withMessages([
                'file' => [__('nova-file-manager::errors.file.upload_validation')],
            ]);
        }

        $receiver = new FileReceiver($index, $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException;
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            $file = $save->getFile();

            // Stream the file to its final location instead of moving it all at once
            $folderPath = dirname($request->filePath());
            $filePath = $file->getClientOriginalName();
            $finalPath = ltrim(str_replace('//', '/', "{$folderPath}/{$filePath}"), '/');

            event(new FileUploading($request->manager()->filesystem(), $request->manager()->getDisk(), $finalPath));

            // Stream in chunks of 1MB
            $stream = fopen($file->getRealPath(), 'r');
            $tempPath = $file->getRealPath();

            try {
                $request->manager()->filesystem()->writeStream(
                    $finalPath,
                    $stream
                );

                if (is_resource($stream)) {
                    fclose($stream);
                }

                // Clean up the temporary file
                @unlink($tempPath);

                event(new FileUploaded($request->manager()->filesystem(), $request->manager()->getDisk(), $finalPath));

                return [
                    'message' => __('nova-file-manager::messages.file.upload'),
                ];
            } catch (\Exception $e) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                @unlink($tempPath);
                throw $e;
            }
        }

        $handler = $save->handler();

        return [
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ];
    }
}
