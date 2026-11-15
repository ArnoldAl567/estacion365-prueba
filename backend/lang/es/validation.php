<?php

return [
    'decimal' => 'El campo :attribute admite hasta dos decimales.',
    'image' => 'El archivo debe ser una fotografía JPG, PNG o WebP.',
    'mimes' => 'Sube una fotografía JPG, PNG o WebP.',
    'dimensions' => 'La fotografía debe medir entre 80 y 6000 píxeles por lado.',
    'required' => 'Completa el campo :attribute.',
    'required_if' => 'Completa el campo :attribute para esta opción.',
    'string' => 'El campo :attribute debe contener texto.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'boolean' => 'El campo :attribute debe ser válido.',
    'array' => 'Revisa el campo :attribute.',
    'in' => 'Elige una opción válida para :attribute.',
    'uuid' => 'El identificador de :attribute no es válido. Vuelve a intentar.',
    'regex' => 'Revisa el formato de :attribute.',
    'alpha_dash' => 'En :attribute usa solo letras sin tildes, números, guiones o guiones bajos.',
    'confirmed' => 'Las contraseñas no coinciden.',
    'unique' => 'Este :attribute ya está registrado.',
    'min' => [
        'string' => 'El campo :attribute necesita al menos :min caracteres.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'array' => 'Agrega al menos :min elemento en :attribute.',
    ],
    'max' => [
        'string' => 'El campo :attribute admite hasta :max caracteres.',
        'numeric' => 'El campo :attribute admite como máximo :max.',
        'array' => 'El campo :attribute admite hasta :max elementos.',
    ],
    'attributes' => [
        'name' => 'nombre', 'username' => 'usuario', 'password' => 'contraseña',
        'role' => 'función', 'active' => 'disponibilidad', 'items' => 'platos',
        'customer.name' => 'nombre', 'customer.phone' => 'teléfono',
        'customer.address' => 'dirección', 'customer.reference' => 'referencia',
        'customer.method' => 'modalidad', 'customer.payment' => 'medio de pago',
        'items.*.quantity' => 'cantidad', 'items.*.notes' => 'indicaciones',
    ],
];
