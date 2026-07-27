<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Exceptions;

/**
 * The package-owned root every exception member implements (STANDARDS §9, design spec §9).
 *
 * An interface, not a class: members legitimately extend different SPL parents (ApiException
 * extends RuntimeException; a future ConfigurationException/AssociationTypeException/
 * ObjectTypeException might extend LogicException instead). A shared interface is the only
 * shape that lets a consumer write one `catch (HubspotException)` block across every member
 * while each still extends the SPL exception that best describes its own failure mode.
 */
interface HubspotException {}
