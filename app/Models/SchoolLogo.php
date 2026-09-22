<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

/**
 * A school's logo, stored in the database rather than on disk (H3).
 *
 * The web, worker and cron containers share one Postgres and nothing else:
 * Render's container filesystem is ephemeral and per service, so a file written
 * by the web service vanished on the next deploy and was never visible to the
 * worker that renders receipt emails. One row per school, keyed by school_id.
 *
 * `data` is base64 text, not bytea: Laravel binds PHP strings as PDO::PARAM_STR
 * and PDO-pgsql sends those as text parameters, so raw image bytes (which
 * contain NULs) would be corrupted on the way in. Base64 is safe on every driver
 * the app runs on and is exactly what the PDF renderer needs anyway.
 */
class SchoolLogo extends Model
{
    protected $primaryKey = 'school_id';

    public $incrementing = false;

    protected $fillable = ['school_id', 'mime', 'size', 'data'];

    /** Never serialised: the payment page passes the school to JSON-producing views. */
    protected $hidden = ['data'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** The row's attributes for an upload the controller has already validated. */
    public static function attributesFor(UploadedFile $file): array
    {
        $bytes = $file->get();

        return [
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => strlen($bytes),
            'data' => base64_encode($bytes),
        ];
    }

    /** The raw image bytes. */
    public function bytes(): string
    {
        return base64_decode($this->data, true) ?: '';
    }

    /** The logo as a data: URI, for renderers that cannot fetch over HTTP (PDF). */
    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.$this->data;
    }

    /**
     * Strong validator for conditional requests. Derived from the stored bytes
     * so it changes exactly when the logo does and is identical on every
     * container, unlike anything based on a file's inode or mtime.
     */
    public function etag(): string
    {
        return '"'.hash('sha256', $this->data).'"';
    }
}
