<?php

namespace Throttle;


class ModuleMaxHeap extends \SplMaxHeap
{
    public function compare($value1, $value2) {
        return ($value1['count'] - $value2['count']);
    }
}
