<?php

namespace Modules\Consultations\Entities;

use CodeIgniter\Entity\Entity;

class LangueEntity extends Entity
{
    // const ID_FRANCAIS = 28; // Identifiant en BD du français.
    protected $datamap = [
        'idLangue' => 'id',
    ];

    protected $casts = [
        'id' => "integer",
    ];
}
