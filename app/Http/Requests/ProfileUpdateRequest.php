<?php

namespace App\Http\Requests;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use App\Rules\ValidPhoneNumberLocal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Pedido explícito del usuario ("nombres, apellidos, fecha de
            // nacimiento... ciudad") — apellido opcional (se pide desde acá,
            // no desde el registro, así que las cuentas viejas no lo tienen
            // todavía); fecha de nacimiento exige mayoría de edad, el mismo
            // requisito que ya declaran los manuales pero que nunca se
            // había validado de verdad en ningún lado.
            'last_name' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:-18 years'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            // Foto de perfil (consideración agregada al alcance): mismo límite
            // de tamaño que las fotos de licencia/vehículo (DriverProfileController).
            'avatar' => ['nullable', 'image', 'max:4096'],
            // Teléfono (pedido explícito del usuario: "que tambien pueda
            // actualizar su numero de telefono") — mismas reglas que ya usa
            // DriverProfileController::update() para el conductor.
            'country_code' => ['nullable', 'string', Rule::in(RegisteredUserController::COUNTRY_CODES)],
            'phone_local' => ['nullable', 'string', new ValidPhoneNumberLocal],
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
            'birth_date.before' => 'Tenés que ser mayor de edad para usar Arka01.',
        ];
    }
}
