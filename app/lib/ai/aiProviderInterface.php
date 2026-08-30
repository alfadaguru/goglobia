<?php

namespace App\lib\ai;

interface aiProviderInterface
{
    /**
     * Extract structured data from an image binary via the configured AI provider.
     *
     * @param string $imageBinary Raw image bytes
     * @param string $mimeType    e.g. image/jpeg
     * @param array  $config      Resolved provider config from passportAiActiveProviderConfig() / ai_* settings
     * @return array{status:bool,message:string,confidence?:float,data?:array,warnings?:array,error_code?:string}
     */
    public function extract(string $imageBinary, string $mimeType, array $config): array;
}
