<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Rules\PdfFile;
use Illuminate\Foundation\Http\FormRequest;

class ResubmitOutgoingDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && $this->user()->can('resubmit', $document);
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file'        => ['required', 'file', 'extensions:pdf', new PdfFile, 'max:10240'],
        ];
    }

    /**
     * "file.uploaded" fires when PHP itself rejects the upload before this
     * request's own validation ever runs (e.g. the file exceeds the
     * server's upload_max_filesize/post_max_size — configured well below
     * this app's own 10 MB limit) — the default message gives no hint of
     * that, and reads like something is wrong with the file itself, which
     * has repeatedly confused users into re-compressing an already-valid
     * PDF instead of asking IT to check the server's PHP configuration.
     */
    public function messages(): array
    {
        return [
            'file.uploaded' => 'The file could not be uploaded — it may be too large for the server to accept right now. If you\'ve already tried a smaller/compressed file and it still fails, this is a server configuration limit, not a problem with your file — please contact your system administrator.',
        ];
    }
}
