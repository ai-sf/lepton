<?php

namespace Lepton\Boson\DataTypes;

use Lepton\Boson\Model;

#[\Attribute]
class ForeignKey extends Relationship
{
    public function __construct(public string $parent, mixed ...$options)
    {
        parent::__construct($parent, ...$options);
    }

    public function validate($value)
    {
        return true;
    }
}
