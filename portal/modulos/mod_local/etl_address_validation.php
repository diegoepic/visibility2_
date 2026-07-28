<?php

/** Reglas ETL trasladadas desde la hoja ETL del libro de locales. */

function etlRemoveDiacritics($value) {
    $value = normalizeCsvText($value);

    if (function_exists('transliterator_transliterate')) {
        $transliterated = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
    } else {
        // Respaldo determinista para instalaciones sin la extension intl.
        $value = strtr($value, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ñ' => 'N', 'ñ' => 'n', 'Ç' => 'C', 'ç' => 'c'
        ]);
    }

    // La descomposicion Unicode puede conservar la letra Ñ en algunos entornos.
    return strtr($value, ['Ñ' => 'N', 'ñ' => 'n']);
}

function etlUpper($value) {
    $value = etlRemoveDiacritics($value);
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }
    $value = strtr($value, [
        'á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú',
        'ü' => 'Ü', 'ñ' => 'Ñ'
    ]);
    return strtoupper($value);
}

function etlPreferredAddress($validation, $cleanAddress) {
    $suggested = normalizeCsvText($validation['suggested_street'] ?? '');
    return $suggested !== '' ? etlUpper($suggested) : etlUpper($cleanAddress);
}

function etlLookupKey($value) {
    $value = etlUpper($value);
    if (function_exists('transliterator_transliterate')) {
        $transliterated = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
    } elseif (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
    }
    return preg_replace('/[^A-Z0-9]+/', '', $value);
}

function etlLoadComunaMapping() {
    static $mapping = null;
    if ($mapping !== null) {
        return $mapping;
    }

    $path = __DIR__ . '/comuna_mapping.json';
    $decoded = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    $mapping = [];
    if (!is_array($decoded)) {
        return $mapping;
    }

    foreach ($decoded as $row) {
        $key = etlLookupKey($row['comuna'] ?? '');
        if ($key === '') {
            continue;
        }
        $mapping[$key] = [
            'comuna' => etlUpper($row['comuna'] ?? ''),
            'distrito' => etlUpper($row['distrito'] ?? ''),
            'region' => etlUpper($row['region'] ?? ''),
            'zona' => etlUpper($row['zona'] ?? '')
        ];
    }
    return $mapping;
}

function etlLookupTerritoryByComuna($comuna) {
    $mapping = etlLoadComunaMapping();
    $key = etlLookupKey($comuna);
    return $mapping[$key] ?? null;
}

function etlNormalizeOptionalPerson($value) {
    $value = normalizeCsvText($value);
    $key = etlLookupKey($value);
    if ($value === '' || $value === '-' || in_array($key, ['NOAPLICA', 'NA', 'NOCORRESPONDE'], true)) {
        return 'NO APLICA';
    }
    return etlUpper($value);
}

function etlCleanAddress($address, $comuna) {
    $text = normalizeCsvText($address);
    $comuna = normalizeCsvText($comuna);

    $commaPos = strpos($text, ',');
    if ($commaPos !== false) {
        $text = substr($text, 0, $commaPos);
    }
    $text = normalizeCsvText(str_replace('#', '', $text));

    if (preg_match('/^(.*\S)\s+(\d+)\s+\2$/u', $text, $matches)) {
        $text = normalizeCsvText($matches[1] . ' ' . $matches[2]);
    }

    $text = preg_replace('/(?<=\D)(\d+)$/u', ' $1', $text);
    $text = normalizeCsvText($text);

    if ($comuna !== '') {
        $withoutComuna = preg_replace('/\s*' . preg_quote($comuna, '/') . '\s*$/iu', '', $text);
        if ($withoutComuna !== null && $withoutComuna !== '') {
            $text = normalizeCsvText($withoutComuna);
        }
    }

    $text = preg_replace('/\s+LOC(?:AL)?\b.*$/iu', '', $text);
    $text = normalizeCsvText($text);

    // El Excel calculaba ESQ en M, pero U dependia de N y lo ignoraba. Aqui se encadena.
    if (preg_match('/^(.*?)\s+ESQ(?:UINA|\.)?\s+(.*)$/iu', $text, $matches)) {
        $before = normalizeCsvText($matches[1]);
        $afterParts = preg_split('/,/', $matches[2], 2);
        $after = normalizeCsvText($afterParts[0]);
        $text = preg_match('/\d/u', $before) ? $before : $after;
    }

    if (preg_match('/^(.*?)\s+SITIO\b.*$/iu', $text, $matches)) {
        $before = normalizeCsvText($matches[1]);
        if (preg_match('/\d/u', $before)) {
            $text = $before;
        }
    }

    return etlUpper($text);
}

function etlBuildLocalName($codigo, $name, $comuna) {
    $codigo = normalizeCsvText($codigo);
    $name = normalizeCsvText($name);
    $comuna = normalizeCsvText($comuna);

    $name = preg_replace('/^' . preg_quote($codigo, '/') . '\s*-\s*/iu', '', $name);
    $name = preg_replace('/^\d+\s*(?:-\s*)?/u', '', $name);
    $name = preg_replace('/\*[^*]*\*/u', ' ', $name);
    $name = normalizeCsvText(str_replace('*', ' ', $name));

    if ($comuna !== '' && !preg_match('/' . preg_quote($comuna, '/') . '\s*$/iu', $name)) {
        $name = normalizeCsvText($name . ' ' . $comuna);
    }

    return etlUpper($codigo . ' - ' . $name);
}

function etlLoadGoogleMapsApiKey() {
    $key = trim((string)getenv('GOOGLE_MAPS_API_KEY'));
    if ($key !== '') {
        return $key;
    }

    $configPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/visibility2/portal/config/maps_config.php';
    if (is_file($configPath)) {
        $config = include $configPath;
        if (is_array($config)) {
            return trim((string)($config['address_validation_api_key'] ?? $config['google_maps_api_key'] ?? ''));
        }
    }
    return '';
}

function etlHasSuspiciousComponent($components) {
    if (!is_array($components)) {
        return false;
    }
    foreach ($components as $component) {
        if (($component['confirmationLevel'] ?? '') === 'UNCONFIRMED_AND_SUSPICIOUS') {
            return true;
        }
    }
    return false;
}

function etlValidateAddress($address, $comuna, $apiKey, $lineNumber) {
    $payload = [
        'address' => [
            'regionCode' => 'CL',
            'locality' => $comuna,
            'addressLines' => [$address]
        ]
    ];

    $url = 'https://addressvalidation.googleapis.com/v1:validateAddress?key=' . rawurlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $base = [
        'status' => 'RECHAZADA', 'reason' => '', 'formatted_address' => '',
        'suggested_street' => '', 'suggested_locality' => '',
        'validation_granularity' => '', 'geocode_granularity' => '',
        'possible_next_action' => '', 'missing_components' => '',
        'unresolved_tokens' => '', 'lat' => null, 'lng' => null, 'response_id' => ''
    ];

    if ($body === false || $curlError !== '') {
        $base['reason'] = "Address Validation API no respondio en la linea $lineNumber: $curlError";
        return $base;
    }

    $decoded = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($decoded)) {
        $apiMessage = is_array($decoded) ? ($decoded['error']['message'] ?? 'respuesta no valida') : 'respuesta no valida';
        $base['reason'] = "Address Validation API devolvio HTTP $httpCode en la linea $lineNumber: $apiMessage";
        return $base;
    }

    $result = $decoded['result'] ?? [];
    $verdict = $result['verdict'] ?? [];
    $validatedAddress = $result['address'] ?? [];
    $postalAddress = $validatedAddress['postalAddress'] ?? [];
    $location = $result['geocode']['location'] ?? [];
    $missing = $validatedAddress['missingComponentTypes'] ?? [];
    $unresolved = $validatedAddress['unresolvedTokens'] ?? [];
    $components = $validatedAddress['addressComponents'] ?? [];

    $base['formatted_address'] = normalizeCsvText($validatedAddress['formattedAddress'] ?? '');
    $base['suggested_street'] = etlUpper(implode(', ', $postalAddress['addressLines'] ?? []));
    $base['suggested_locality'] = etlUpper($postalAddress['locality'] ?? '');
    $base['validation_granularity'] = $verdict['validationGranularity'] ?? '';
    $base['geocode_granularity'] = $verdict['geocodeGranularity'] ?? '';
    $base['possible_next_action'] = $verdict['possibleNextAction'] ?? '';
    $base['missing_components'] = implode(', ', $missing);
    $base['unresolved_tokens'] = implode(', ', $unresolved);
    $base['lat'] = isset($location['latitude']) ? (float)$location['latitude'] : null;
    $base['lng'] = isset($location['longitude']) ? (float)$location['longitude'] : null;
    $base['response_id'] = $decoded['responseId'] ?? '';

    $addressComplete = !empty($verdict['addressComplete']);
    $hasUnconfirmed = !empty($verdict['hasUnconfirmedComponents']);
    $hasReplaced = !empty($verdict['hasReplacedComponents']);
    $hasSuspicious = etlHasSuspiciousComponent($components);
    $granularityOk = in_array($base['validation_granularity'], ['PREMISE', 'SUB_PREMISE'], true);
    $hasCoordinates = $base['lat'] !== null && $base['lng'] !== null;
    $nextAction = $base['possible_next_action'];

    $canAcceptWithoutPreviewField = $nextAction === '' && $addressComplete && $granularityOk
        && !$hasUnconfirmed && !$hasReplaced && !$hasSuspicious
        && empty($missing) && empty($unresolved) && $hasCoordinates;

    $explicitAccept = $nextAction === 'ACCEPT' && $addressComplete && $granularityOk
        && !$hasUnconfirmed && !$hasReplaced && !$hasSuspicious
        && empty($missing) && empty($unresolved) && $hasCoordinates;

    if ($explicitAccept || $canAcceptWithoutPreviewField) {
        $base['status'] = 'ACEPTADA';
        $base['reason'] = 'Direccion completa y validada por Google.';
        if ($base['suggested_street'] === '') {
            $base['suggested_street'] = etlUpper($address);
        }
        return $base;
    }

    $needsFix = $nextAction === 'FIX' || $base['validation_granularity'] === 'OTHER'
        || !$addressComplete || !empty($missing) || !empty($unresolved)
        || $hasSuspicious || !$hasCoordinates;

    if ($needsFix) {
        $base['status'] = 'RECHAZADA';
        $base['reason'] = 'Direccion incompleta, sospechosa o no ubicable con precision suficiente.';
    } else {
        $base['status'] = 'REVISION';
        $base['reason'] = 'Google encontro una direccion posible, pero requiere confirmacion antes de crear el local.';
    }

    return $base;
}
