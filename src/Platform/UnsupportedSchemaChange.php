<?php

namespace Dudev\YdbDoctrine\Platform;

/** A schema diff asked for something YDB's ALTER TABLE cannot do. */
final class UnsupportedSchemaChange extends \LogicException
{
}
