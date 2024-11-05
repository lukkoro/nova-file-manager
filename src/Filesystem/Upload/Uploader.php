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
            error_log(sprintf(
                "Starting final file save - Size: %s, Name: %s",
                $save->getFile()->getSize(),
                $save->getFile()->getClientOriginalName()
            ));

            $startTime = microtime(true);
            $result = $this->saveFile($request, $save->getFile());
            $endTime = microtime(true);

            error_log(sprintf("Finished file save - Duration: %.2f seconds", $endTime - $startTime));

            return $result;
        }

        $handler = $save->handler();

        error_log(sprintf(
            "Chunk processed - Percentage: %s, Chunk: %s, Total Chunks: %s",
            $handler->getPercentageDone(),
            $request->input('resumableChunkNumber'),
            $request->input('resumableTotalChunks')
        ));

        return [
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ];
    }

    public function saveFile(UploadFileRequest $request, UploadedFile $file): array
    {
        if (!$request->validateUpload($file, true)) {
            throw ValidationException::withMessages([
                'file' => [__('nova-file-manager::errors.file.upload_validation')],
            ]);
        }

        $folderPath = dirname($request->filePath());
        $filePath = $file->getClientOriginalName();
        $testPath = ltrim(str_replace('//', '/', "{$folderPath}/{$filePath}"), '/');

        event(new FileUploading($request->manager()->filesystem(), $request->manager()->getDisk(), $testPath));

        error_log(sprintf(
            "Starting filesystem put - Path: %s, Size: %s bytes",
            $testPath,
            $file->getSize()
        ));

        $startTime = microtime(true);
        $path = $request->manager()->filesystem()->putFileAs(
            path: $folderPath,
            file: $file,
            name: $filePath,
        );
        $endTime = microtime(true);

        error_log(sprintf(
            "Finished filesystem put - Duration: %.2f seconds, Path: %s",
            $endTime - $startTime,
            $path
        ));

        event(new FileUploaded($request->manager()->filesystem(), $request->manager()->getDisk(), $path));

        return [
            'message' => __('nova-file-manager::messages.file.upload'),
        ];
    }
}
