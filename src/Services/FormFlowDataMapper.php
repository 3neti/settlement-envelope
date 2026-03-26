<?php

declare(strict_types=1);

namespace LBHurtado\SettlementEnvelope\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use LBHurtado\SettlementEnvelope\Data\FormFlowMappingData;

/**
 * Maps form flow collected data to settlement envelope payload and attachments.
 *
 * Supports two modes:
 * 1. Config-driven: Uses FormFlowMappingData from driver YAML for declarative field mapping
 * 2. Hardcoded fallback: Uses built-in defaults when no config provided (backward compatible)
 *
 * Mapping syntax (config-driven):
 *   - Simple: "bio_fields.name" → Arr::get($data, 'bio_fields.name')
 *   - Fallback: "bio_fields.full_name | bio_fields.name" → tries first, falls back to second
 *   - Type cast: "location_capture.latitude:float" → casts to float
 *   - Type cast: "bio_fields.verified:bool" → casts to boolean
 *
 * Form flow data formats handled:
 *   1. Numeric-indexed: [0 => ['_step_name' => 'wallet_info', 'mobile' => '...'], ...]
 *   2. Step-name-keyed: ['wallet_info' => ['mobile' => '...'], ...]
 */
class FormFlowDataMapper
{
    /**
     * Map collected form flow data to envelope payload structure.
     *
     * @param  array  $collectedData  Form flow collected data
     * @param  FormFlowMappingData|null  $mapping  Optional config-driven mapping from driver
     * @return array Envelope payload structure
     */
    public function toPayload(array $collectedData, ?FormFlowMappingData $mapping = null): array
    {
        $collectedData = $this->normalizeCollectedData($collectedData);

        Log::debug('[FormFlowDataMapper] Normalized data for payload', [
            'keys' => array_keys($collectedData),
            'has_mapping' => $mapping !== null,
        ]);

        // Use config-driven mapping if available, otherwise hardcoded defaults
        return $mapping && ! $mapping->isEmpty()
            ? $this->toPayloadFromConfig($collectedData, $mapping)
            : $this->toPayloadHardcoded($collectedData);
    }

    /**
     * Extract attachments from collected form flow data.
     *
     * @param  array  $collectedData  Form flow collected data
     * @param  FormFlowMappingData|null  $mapping  Optional config-driven mapping from driver
     * @return array<string, UploadedFile> Attachments keyed by document type
     */
    public function extractAttachments(array $collectedData, ?FormFlowMappingData $mapping = null): array
    {
        $collectedData = $this->normalizeCollectedData($collectedData);

        Log::debug('[FormFlowDataMapper] Normalized data for attachments', [
            'keys' => array_keys($collectedData),
            'has_mapping' => $mapping !== null,
        ]);

        // Use config-driven mapping if available, otherwise hardcoded defaults
        return $mapping && ! $mapping->isEmpty()
            ? $this->extractAttachmentsFromConfig($collectedData, $mapping)
            : $this->extractAttachmentsHardcoded($collectedData);
    }

    // ========================================================================
    // Config-driven mapping (uses FormFlowMappingData from driver YAML)
    // ========================================================================

    /**
     * Build payload using config-driven mapping.
     */
    protected function toPayloadFromConfig(array $data, FormFlowMappingData $mapping): array
    {
        $payload = [];

        foreach ($mapping->payload as $section => $fields) {
            $sectionData = [];

            foreach ($fields as $targetField => $sourceExpr) {
                $value = $this->resolveExpression($data, $sourceExpr);
                if ($value !== null) {
                    $sectionData[$targetField] = $value;
                }
            }

            if (! empty($sectionData)) {
                $payload[$section] = $sectionData;
            }
        }

        // Handle nested KYC modules array (HyperVerge structure)
        // Config-driven mapping expects flat fields, but KYC data may have nested modules
        $modules = Arr::get($data, 'kyc_verification.modules', []);
        if (! empty($modules) && isset($payload['kyc'])) {
            // Extract image URLs from modules
            $extractedImages = $this->extractKYCImageUrlsFromModules($modules);
            $payload['kyc'] = array_merge($payload['kyc'], $extractedImages);

            // Also extract ID details if not already present
            $extractedDetails = $this->extractKYCDetailsFromModules($modules);
            if (empty($payload['kyc']['id_type']) && ! empty($extractedDetails['id_type'])) {
                $payload['kyc']['id_type'] = $extractedDetails['id_type'];
            }
            if (empty($payload['kyc']['id_number']) && ! empty($extractedDetails['id_number'])) {
                $payload['kyc']['id_number'] = $extractedDetails['id_number'];
            }
            if (empty($payload['kyc']['verified_name']) && ! empty($extractedDetails['full_name'])) {
                $payload['kyc']['verified_name'] = $extractedDetails['full_name'];
            }
            if (empty($payload['kyc']['verified_birth_date']) && ! empty($extractedDetails['date_of_birth'])) {
                $payload['kyc']['verified_birth_date'] = $extractedDetails['date_of_birth'];
            }
            if (empty($payload['kyc']['verified_address']) && ! empty($extractedDetails['address'])) {
                $payload['kyc']['verified_address'] = $extractedDetails['address'];
            }
        } elseif (! empty($modules) && ! isset($payload['kyc'])) {
            // No kyc section in payload yet, create one from modules
            $kycData = [
                'status' => Arr::get($data, 'kyc_verification.status'),
                'transaction_id' => Arr::get($data, 'kyc_verification.transaction_id'),
            ];
            $kycData = array_merge($kycData, $this->extractKYCImageUrlsFromModules($modules));
            $kycData = array_merge($kycData, $this->extractKYCDetailsFromModules($modules));
            $payload['kyc'] = array_filter($kycData);
        }

        return $payload;
    }

    /**
     * Extract attachments using config-driven mapping.
     */
    protected function extractAttachmentsFromConfig(array $data, FormFlowMappingData $mapping): array
    {
        $attachments = [];

        foreach ($mapping->attachments as $docType => $config) {
            $base64 = Arr::get($data, $config->source);
            if ($base64) {
                $file = $this->base64ToUploadedFile($base64, $config->filename, $config->mime);
                if ($file) {
                    $attachments[$docType] = $file;
                }
            }
        }

        return $attachments;
    }

    /**
     * Resolve a mapping expression to a value.
     *
     * Supports:
     *   - Simple path: "bio_fields.name"
     *   - Fallback: "bio_fields.full_name | bio_fields.name"
     *   - Type cast: "location_capture.latitude:float"
     */
    protected function resolveExpression(array $data, string $expr): mixed
    {
        // Handle fallback syntax: "path1 | path2"
        if (str_contains($expr, '|')) {
            $paths = array_map('trim', explode('|', $expr));
            foreach ($paths as $path) {
                $value = $this->resolveExpression($data, $path);
                if ($value !== null) {
                    return $value;
                }
            }

            return null;
        }

        // Handle type cast suffix: ":float", ":int", ":bool", ":string"
        $type = null;
        if (preg_match('/^(.+):([a-z]+)$/', $expr, $matches)) {
            $expr = $matches[1];
            $type = $matches[2];
        }

        $value = Arr::get($data, $expr);

        if ($value === null) {
            return null;
        }

        // Apply type cast
        return match ($type) {
            'float' => (float) $value,
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default => $value,
        };
    }

    // ========================================================================
    // Hardcoded mapping (backward compatible defaults)
    // ========================================================================

    /**
     * Build payload using hardcoded defaults.
     */
    protected function toPayloadHardcoded(array $collectedData): array
    {
        $payload = [];

        // Redeemer info from wallet_info and bio_fields
        $redeemer = [];

        // From wallet_info step
        if ($mobile = Arr::get($collectedData, 'wallet_info.mobile')) {
            $redeemer['mobile'] = $mobile;
        }

        // From bio_fields step (try 'full_name' first, fallback to 'name')
        $name = Arr::get($collectedData, 'bio_fields.full_name')
            ?? Arr::get($collectedData, 'bio_fields.name');
        if ($name) {
            $redeemer['name'] = $name;
        }
        if ($birthDate = Arr::get($collectedData, 'bio_fields.birth_date')) {
            $redeemer['birth_date'] = $birthDate;
        }
        if ($address = Arr::get($collectedData, 'bio_fields.address')) {
            $redeemer['address'] = $address;
        }
        if ($email = Arr::get($collectedData, 'bio_fields.email')) {
            $redeemer['email'] = $email;
        }
        if ($referenceCode = Arr::get($collectedData, 'bio_fields.reference_code')) {
            $redeemer['reference_code'] = $referenceCode;
        }
        if ($income = Arr::get($collectedData, 'bio_fields.gross_monthly_income')) {
            $redeemer['gross_monthly_income'] = $income;
        }

        if (! empty($redeemer)) {
            $payload['redeemer'] = $redeemer;
        }

        // Wallet info
        $wallet = [];
        if ($bankCode = Arr::get($collectedData, 'wallet_info.bank_code')) {
            $wallet['bank_code'] = $bankCode;
        }
        if ($accountNumber = Arr::get($collectedData, 'wallet_info.account_number')) {
            $wallet['account_number'] = $accountNumber;
        }
        if ($settlementRail = Arr::get($collectedData, 'wallet_info.settlement_rail')) {
            $wallet['settlement_rail'] = $settlementRail;
        }

        if (! empty($wallet)) {
            $payload['wallet'] = $wallet;
        }

        // KYC info from kyc_verification step
        $kyc = [];
        if ($kycStatus = Arr::get($collectedData, 'kyc_verification.status')) {
            $kyc['status'] = $kycStatus;
        }
        if ($transactionId = Arr::get($collectedData, 'kyc_verification.transaction_id')) {
            $kyc['transaction_id'] = $transactionId;
        }
        // ID document info extracted from HyperVerge
        if ($idType = Arr::get($collectedData, 'kyc_verification.id_type')) {
            $kyc['id_type'] = $idType;
        }
        if ($idNumber = Arr::get($collectedData, 'kyc_verification.id_number')) {
            $kyc['id_number'] = $idNumber;
        }
        // Verified bio data from KYC (prefixed with 'verified_' to distinguish from user-entered)
        if ($verifiedName = Arr::get($collectedData, 'kyc_verification.name')) {
            $kyc['verified_name'] = $verifiedName;
        }
        if ($verifiedBirthDate = Arr::get($collectedData, 'kyc_verification.date_of_birth')) {
            $kyc['verified_birth_date'] = $verifiedBirthDate;
        }
        if ($verifiedAddress = Arr::get($collectedData, 'kyc_verification.address')) {
            $kyc['verified_address'] = $verifiedAddress;
        }
        if ($nationality = Arr::get($collectedData, 'kyc_verification.nationality')) {
            $kyc['nationality'] = $nationality;
        }

        // Extract image URLs from nested modules array (HyperVerge KYC structure)
        $modules = Arr::get($collectedData, 'kyc_verification.modules', []);
        if (! empty($modules)) {
            $extractedImages = $this->extractKYCImageUrlsFromModules($modules);
            $kyc = array_merge($kyc, $extractedImages);

            // Also extract ID details from modules if not already present
            $extractedDetails = $this->extractKYCDetailsFromModules($modules);
            if (empty($kyc['id_type']) && ! empty($extractedDetails['id_type'])) {
                $kyc['id_type'] = $extractedDetails['id_type'];
            }
            if (empty($kyc['id_number']) && ! empty($extractedDetails['id_number'])) {
                $kyc['id_number'] = $extractedDetails['id_number'];
            }
            if (empty($kyc['verified_name']) && ! empty($extractedDetails['full_name'])) {
                $kyc['verified_name'] = $extractedDetails['full_name'];
            }
            if (empty($kyc['verified_birth_date']) && ! empty($extractedDetails['date_of_birth'])) {
                $kyc['verified_birth_date'] = $extractedDetails['date_of_birth'];
            }
            if (empty($kyc['verified_address']) && ! empty($extractedDetails['address'])) {
                $kyc['verified_address'] = $extractedDetails['address'];
            }
        }

        if (! empty($kyc)) {
            $payload['kyc'] = $kyc;
        }

        // Location info from location_capture step
        $location = [];
        if ($latitude = Arr::get($collectedData, 'location_capture.latitude')) {
            $location['latitude'] = (float) $latitude;
        }
        if ($longitude = Arr::get($collectedData, 'location_capture.longitude')) {
            $location['longitude'] = (float) $longitude;
        }
        if ($accuracy = Arr::get($collectedData, 'location_capture.accuracy')) {
            $location['accuracy'] = (float) $accuracy;
        }
        // Address can be nested or flat
        $formattedAddress = Arr::get($collectedData, 'location_capture.address.formatted')
            ?? Arr::get($collectedData, 'location_capture.formatted_address');
        if ($formattedAddress) {
            $location['formatted_address'] = $formattedAddress;
        }
        if ($capturedAt = Arr::get($collectedData, 'location_capture.timestamp')) {
            $location['captured_at'] = $capturedAt;
        }

        if (! empty($location)) {
            $payload['location'] = $location;
        }

        return $payload;
    }

    /**
     * Extract attachments using hardcoded defaults.
     */
    protected function extractAttachmentsHardcoded(array $collectedData): array
    {
        $attachments = [];

        // Selfie from selfie_capture step
        if ($selfieBase64 = Arr::get($collectedData, 'selfie_capture.selfie')) {
            $file = $this->base64ToUploadedFile($selfieBase64, 'selfie.jpg', 'image/jpeg');
            if ($file) {
                $attachments['SELFIE'] = $file;
            }
        }

        // Signature from signature_capture step
        if ($signatureBase64 = Arr::get($collectedData, 'signature_capture.signature')) {
            $file = $this->base64ToUploadedFile($signatureBase64, 'signature.png', 'image/png');
            if ($file) {
                $attachments['SIGNATURE'] = $file;
            }
        }

        // Map snapshot from location_capture step
        if ($mapBase64 = Arr::get($collectedData, 'location_capture.map')) {
            $file = $this->base64ToUploadedFile($mapBase64, 'map_snapshot.png', 'image/png');
            if ($file) {
                $attachments['MAP_SNAPSHOT'] = $file;
            }
        }

        // KYC images (if available from HyperVerge)
        if ($idFront = Arr::get($collectedData, 'kyc_verification.id_front')) {
            $file = $this->base64ToUploadedFile($idFront, 'kyc_id_front.jpg', 'image/jpeg');
            if ($file) {
                $attachments['KYC_ID_FRONT'] = $file;
            }
        }
        if ($idBack = Arr::get($collectedData, 'kyc_verification.id_back')) {
            $file = $this->base64ToUploadedFile($idBack, 'kyc_id_back.jpg', 'image/jpeg');
            if ($file) {
                $attachments['KYC_ID_BACK'] = $file;
            }
        }
        if ($kycSelfie = Arr::get($collectedData, 'kyc_verification.kyc_selfie')) {
            $file = $this->base64ToUploadedFile($kycSelfie, 'kyc_selfie.jpg', 'image/jpeg');
            if ($file) {
                $attachments['KYC_SELFIE'] = $file;
            }
        }

        return $attachments;
    }

    // ========================================================================
    // KYC module extraction helpers
    // ========================================================================

    /**
     * Extract image URLs from HyperVerge KYC modules array.
     *
     * Module names from HyperVerge:
     * - "ID Card Validation front" or "ID Card Validation" or "id_card" → ID card image
     * - "Selfie Validation" or "selfie" → Selfie image
     *
     * @param  array  $modules  Array of KYC modules from HyperVerge
     * @return array Extracted image URLs keyed by type
     */
    protected function extractKYCImageUrlsFromModules(array $modules): array
    {
        $images = [];

        foreach ($modules as $module) {
            if (! is_array($module)) {
                continue;
            }

            $moduleName = $module['module'] ?? '';

            // ID Card module - check various possible names
            if (in_array($moduleName, ['id_card', 'ID Card Validation', 'ID Card Validation front'])) {
                if (! empty($module['imageUrl'])) {
                    $images['id_card_full_url'] = $module['imageUrl'];
                }
                if (! empty($module['croppedImageUrl'])) {
                    $images['id_card_cropped_url'] = $module['croppedImageUrl'];
                }
            }

            // Selfie module - check various possible names
            if (in_array($moduleName, ['selfie', 'Selfie Validation'])) {
                if (! empty($module['imageUrl'])) {
                    $images['selfie_url'] = $module['imageUrl'];
                }
            }
        }

        return $images;
    }

    /**
     * Extract ID details from HyperVerge KYC modules array.
     *
     * @param  array  $modules  Array of KYC modules from HyperVerge
     * @return array Extracted ID details
     */
    protected function extractKYCDetailsFromModules(array $modules): array
    {
        $details = [];

        foreach ($modules as $module) {
            if (! is_array($module)) {
                continue;
            }

            $moduleName = $module['module'] ?? '';

            // ID Card module contains document details
            if (in_array($moduleName, ['id_card', 'ID Card Validation', 'ID Card Validation front'])) {
                $moduleDetails = $module['details'] ?? [];

                // Map camelCase to snake_case (HyperVerge uses both)
                if (! empty($moduleDetails['idType']) || ! empty($moduleDetails['id_type'])) {
                    $details['id_type'] = $moduleDetails['idType'] ?? $moduleDetails['id_type'];
                }
                if (! empty($moduleDetails['idNumber']) || ! empty($moduleDetails['id_number'])) {
                    $details['id_number'] = $moduleDetails['idNumber'] ?? $moduleDetails['id_number'];
                }
                if (! empty($moduleDetails['fullName']) || ! empty($moduleDetails['full_name'])) {
                    $details['full_name'] = $moduleDetails['fullName'] ?? $moduleDetails['full_name'];
                }
                if (! empty($moduleDetails['dateOfBirth']) || ! empty($moduleDetails['date_of_birth'])) {
                    $details['date_of_birth'] = $moduleDetails['dateOfBirth'] ?? $moduleDetails['date_of_birth'];
                }
                if (! empty($moduleDetails['address'])) {
                    $details['address'] = $moduleDetails['address'];
                }
            }
        }

        return $details;
    }

    // ========================================================================
    // Utility methods
    // ========================================================================

    /**
     * Convert base64 encoded image to UploadedFile.
     *
     * @param  string  $base64  Base64 encoded image (with or without data URI prefix)
     * @param  string  $filename  Desired filename
     * @param  string  $mimeType  MIME type
     */
    protected function base64ToUploadedFile(string $base64, string $filename, string $mimeType): ?UploadedFile
    {
        try {
            // Remove data URI prefix if present (e.g., "data:image/jpeg;base64,")
            if (str_contains($base64, ',')) {
                $base64 = explode(',', $base64, 2)[1];
            }

            $decoded = base64_decode($base64, true);
            if ($decoded === false) {
                Log::warning('[FormFlowDataMapper] Failed to decode base64', [
                    'filename' => $filename,
                ]);

                return null;
            }

            // Write to temp file
            $tempPath = sys_get_temp_dir().'/'.uniqid('formflow_', true).'_'.$filename;
            file_put_contents($tempPath, $decoded);

            return new UploadedFile(
                path: $tempPath,
                originalName: $filename,
                mimeType: $mimeType,
                error: UPLOAD_ERR_OK,
                test: true // Mark as test to skip is_uploaded_file check
            );
        } catch (\Throwable $e) {
            Log::warning('[FormFlowDataMapper] Failed to convert base64 to file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize collected data to step-name keyed format.
     *
     * Form flow may return data in two formats:
     * 1. Numeric-indexed: [0 => ['_step_name' => 'wallet_info', ...], 1 => [...]]
     * 2. Step-name-keyed: ['wallet_info' => [...], 'bio_fields' => [...]]
     *
     * This method converts format 1 to format 2 for consistent access.
     *
     * @param  array  $collectedData  Raw collected data from form flow
     * @return array Normalized data keyed by step_name
     */
    protected function normalizeCollectedData(array $collectedData): array
    {
        // If already keyed by step names (string keys), return as-is
        $firstKey = array_key_first($collectedData);
        if ($firstKey !== null && is_string($firstKey) && ! is_numeric($firstKey)) {
            return $collectedData;
        }

        // Convert numeric-indexed to step-name-keyed
        $normalized = [];
        foreach ($collectedData as $stepData) {
            if (! is_array($stepData)) {
                continue;
            }

            // Extract step_name from the data (stored as '_step_name' field)
            $stepName = $stepData['_step_name'] ?? null;
            if (! $stepName) {
                // Try without underscore prefix
                $stepName = $stepData['step_name'] ?? null;
            }

            // Detect KYC step by its structure (has 'modules' and 'transaction_id')
            if (! $stepName && isset($stepData['modules']) && isset($stepData['transaction_id'])) {
                $stepName = 'kyc_verification';
            }

            if ($stepName) {
                // Remove the _step_name field from the data itself
                unset($stepData['_step_name'], $stepData['step_name']);
                $normalized[$stepName] = $stepData;
            }
        }

        return $normalized;
    }
}
