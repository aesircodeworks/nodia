<?php

namespace App\Identity\Enums;

/**
 * The result of resolving whether an authenticated staff user may act as
 * one tenant (stage-03 plan, Slice 3): Denied means neither a matching
 * tenant-scope membership nor any platform-scope membership exists;
 * TenantMember means an ordinary membership in exactly that tenant was
 * found; PlatformMember means the caller holds a platform-scope
 * membership, which reaches every tenant through the platform role
 * (system-design 4.3). Deliberately not a backed enum: the
 * TypeScriptTransformerServiceProvider's EnumTransformer only accepts
 * backed enums as a valid union (Spatie\TypeScriptTransformer\Transformers\
 * EnumProviders\PhpEnumProvider::isValidUnion()), so this internal
 * resolution result, never part of the wire contract, is skipped by the
 * generator instead of leaking into packages/api-client/src/generated.
 */
enum MembershipAccessOutcome
{
    case Denied;
    case TenantMember;
    case PlatformMember;
}
