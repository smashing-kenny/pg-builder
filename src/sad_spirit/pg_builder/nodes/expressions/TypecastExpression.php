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
    sad_spirit\pg_builder\nodes\TypeName,
    sad_spirit\pg_builder\nodes\ScalarExpression,
    sad_spirit\pg_builder\TreeWalker;

/**
 * AST node representing a conversion of some value to a given datatype
 *
 * All possible type casting expressions are represented by this node:
 *  * CAST(foo as bar)
 *  * foo::bar
 *  * bar 'string constant'
 *
 * @property      ScalarExpression $argument
 * @property-read TypeName         $type
 */
class TypecastExpression extends Node implements ScalarExpression
{
    public function __construct(ScalarExpression $argument, TypeName $type)
    {
        $this->setNamedProperty('argument', $argument);
        $this->setNamedProperty('type', $type);
    }

    public function setArgument(ScalarExpression $argument)
    {
        $this->setNamedProperty('argument', $argument);
    }

    public function dispatch(TreeWalker $walker)
    {
        return $walker->walkTypecastExpression($this);
    }
}