<?php

declare(strict_types=1);

namespace Prov\Model;

/**
 * Marker interface for PROV-DM relations, distinguishing records like
 * Generation or Usage from the element types (Entity, Activity, Agent).
 */
interface ProvRelationInterface extends ProvRecordInterface {}
