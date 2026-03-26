<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used for storing envelope driver YAML files.
    | Configure this disk in config/filesystems.php.
    |
    */

    'driver_disk' => env('ENVELOPE_DRIVER_DISK', 'envelope-drivers'),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The disk to use for storing envelope attachments.
    | Uses Spatie Media Library under the hood.
    |
    */

    'storage_disk' => env('ENVELOPE_STORAGE_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Audit Log
    |--------------------------------------------------------------------------
    |
    | Configure audit logging behavior.
    |
    */

    'audit' => [
        'enabled' => env('ENVELOPE_AUDIT_ENABLED', true),
        'capture' => [
            'payload_patch',
            'attachment_upload',
            'attachment_review',
            'attestation_submit',
            'signal_set',
            'gate_change',
            'status_change',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Generation
    |--------------------------------------------------------------------------
    |
    | Configure manifest/integrity hash generation.
    |
    */

    'manifest' => [
        'enabled' => env('ENVELOPE_MANIFEST_ENABLED', true),
        'algorithm' => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Actor Model
    |--------------------------------------------------------------------------
    |
    | The default model class to use for actors (users performing actions).
    | Host app can override this.
    |
    */

    'actor_model' => env('ENVELOPE_ACTOR_MODEL', 'App\\Models\\User'),

];
