<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DocentesImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        // No se procesa aquí; solo se usa toCollection en el service.
        return $rows;
    }
}
