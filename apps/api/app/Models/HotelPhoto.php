<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'path', 'mime_type', 'caption'])]
class HotelPhoto extends Model {}
