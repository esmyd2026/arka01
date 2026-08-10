<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            // Foto de perfil (consideración agregada al alcance): mismo límite
            // de tamaño que las fotos de licencia/vehículo (DriverProfileController).
            'avatar' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /**
     * Mensajes en español (pedido explícito del usuario: el mensaje de "la
     * foto pesa demasiado" salía en inglés porque no existe lang/es/validation.php
     * — Laravel cae al inglés del framework para cualquier regla sin mensaje
     * propio, no solo esta. Se cubre acá puntualmente en vez de publicar todo
     * el archivo de validación del framework, que sería mucho más para
     * traducir de lo que hace falta.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.image' => 'El archivo tiene que ser una imagen (JPG, PNG o similar).',
            'avatar.max' => 'La foto pesa demasiado — el máximo es 4 MB. Probá con una de menor resolución o comprimida.',
        ];
    }
}
