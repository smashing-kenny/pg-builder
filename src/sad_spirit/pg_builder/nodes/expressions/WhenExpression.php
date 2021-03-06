<?php
/**
 * Query builder for PostgreSQL backed by a query parser
 *
 * LICENSE
 *
 * This source file is subject to BSD 2-Clause License that is bundled
 * with this package in the file LICENSE and available at the URL
 * https://raw.githubusercontent.com/sad-spirit/pg-builder/master/LICENSE
 *
 * @package   sad_spirit\pg_builder
 * @copyright 2014-2018 Alexey Borzov
 * @author    Alexey Borzov <avb@php.net>
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD 2-Clause license
 * @link      https://github.com/sad-spirit/pg-builder
 */

namespace sad_spirit\pg_builder\nodes\expressions;

use sad_spirit\pg_builder\Node,
    sad_spirit\pg_builder\nodes\ScalarExpression;

/**
 * Part of a CASE expression: WHEN Expression THEN Expression
 *
 * @property ScalarExpression $when
 * @property ScalarExpression $then
 */
class WhenExpression extends Node
{
    public function __construct(ScalarExpression $when, ScalarExpression $then)
    {
        $this->setNamedProperty('when', $when);
        $this->setNamedProperty('then', $then);
    }

    public function setWhen(ScalarExpression $when)
    {
        $this->setNamedProperty('when', $when);
    }

    public function setThen(ScalarExpression $then)
    {
        $this->setNamedProperty('then', $then);
    }
}
