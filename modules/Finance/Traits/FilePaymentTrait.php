<?php

namespace Modules\Finance\Traits; 

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Finance\Helpers\PaymentFileHelper;

trait FilePaymentTrait
{ 

    public function saveFiles($record, $request, $type)
    {
        
        $temp_path = $request->temp_path;

        if($temp_path) {

            $resolved = PaymentFileHelper::resolveValidated($temp_path);
            $file_content = File::get($resolved['path']);
            if (!is_string($file_content)) {
                throw new \RuntimeException('Unable to read temporary payment attachment.');
            }
            $file_name = 'payment-'.Str::uuid().'.'.$resolved['extension'];
            $path = 'payment_files'.DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.$file_name;
            $disk = Storage::disk('tenant');
            if (!$disk->put($path, $file_content)) {
                throw new \RuntimeException('Unable to store payment attachment.');
            }
            try {
                $attachment = $record->payment_file()->create(['filename' => $file_name]);
                if (!$attachment || !$attachment->exists) {
                    throw new \RuntimeException('Unable to save payment attachment relation.');
                }
            } catch (\Throwable $exception) {
                try { $disk->delete($path); } catch (\Throwable $ignored) {}
                throw $exception;
            }
            $cleanup = PaymentFileHelper::cleanup($resolved);
            $connection = $record->getConnection();
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($cleanup);
            } else {
                $cleanup();
            }

        }

    } 

 
}
