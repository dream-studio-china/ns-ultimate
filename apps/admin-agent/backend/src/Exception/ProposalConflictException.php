<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A stale proposal was rejected before domain mutation; not an ambiguous write outcome. */
final class ProposalConflictException extends ConflictHttpException {}
