<?php

namespace App\Http\Requests;

use App\Rules\ValidPhoneNumberLocal;
use App\Services\Profile\ProfileUpdater;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Pedido explícito del usuario: si escribe el 0 inicial (ej.
     * "0988492339"), se lo quitamos solo en vez de rechazarlo — mismo
     * criterio que el resto de los formularios de teléfono. Acá tiene que
     * ir en prepareForValidation() y no en el controlador (como hacen los
     * demás): un FormRequest valida antes de que el método del controlador
     * llegue a ejecutarse, así que un merge() ahí llegaría tarde.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_local' => ValidPhoneNumberLocal::normalize($this->input('country_code'), $this->input('phone_local')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, Rule|array|string>
     */
    public function rules(): array
    {
        return ProfileUpdater::rules($this->user()->id);
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
            'birth_date.before_or_equal' => 'La fecha ingresada debe corresponder a una persona de 18 años o más.',
        ];
    }
}
