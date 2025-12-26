<?php

namespace RSE\DynaFlow\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RSE\DynaFlow\Tests\Fixtures\TestModelFactory;
// use RSE\DynaFlow\Traits\HasDrafts;
use RSE\DynaFlow\Traits\HasDynaflows;

class TestModel extends Model
{
    # use HasDrafts;
    use HasDynaflows;
    use HasFactory;

    protected $table = 'test_models';

    protected $guarded = ['is_draft', 'replaces_id'];

    protected static function newFactory(): TestModelFactory
    {
        return TestModelFactory::new();
    }
}
