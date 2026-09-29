<?php

namespace App\Http\Requests;

use App\Models\UserSigningCredential;
use Illuminate\Foundation\Http\FormRequest;

class StoreSigningCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('upload', UserSigningCredential::class);
    }

    public function rules(): array
    {
        return [
            'p12_file'     => ['required', 'file', 'extensions:p12,pfx', 'max:5120'],
            'p12_password' => ['required', 'string', 'max:255'],
            'esig_image'   => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            if (! $this->hasFile('p12_file') || ! $this->input('p12_password')) {
                return;
            }

            $certs = [];
            $p12Content = file_get_contents($this->file('p12_file')->getRealPath());

            if (! @openssl_pkcs12_read($p12Content, $certs, $this->input('p12_password'))) {
                $v->errors()->add(
                    'p12_password',
                    'Could not open the P12 file. Please verify the file and password are correct.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'p12_file.extensions' => 'The certificate file must be a .p12 or .pfx file.',
        ];
    }
}
