<?php
declare(strict_types=1);

namespace PanelEncuesta\ValueObjects;

/**
 * Validated + normalized container for a panel filter request.
 *
 * Keeps the raw $src array intact so it can be passed directly to
 * build_panel_encuesta_filters(), which already does deep sanitization.
 * This object adds the session-derived context and pagination params.
 */
final class FilterParams
{
    // Session context
    public readonly int  $empresaId;
    public readonly int  $userDiv;
    public readonly bool $isMc;

    // Pagination
    public readonly int  $page;
    public readonly int  $limit;
    public readonly int  $offset;
    public readonly bool $wantFacets;

    // Security
    public readonly string $csrfToken;

    // Raw source array (GET/POST) passed through to build_panel_encuesta_filters()
    public readonly array $src;

    // Options for build_panel_encuesta_filters()
    public readonly array $buildOpts;

    private function __construct() {}

    public static function fromRequest(
        array $get,
        array $session,
        array $buildOpts = []
    ): self {
        $self = new self();

        $self->empresaId  = (int)($session['empresa_id']  ?? 0);
        $self->userDiv    = (int)($session['division_id'] ?? 0);
        $self->isMc       = ($self->userDiv === 1);

        $self->page       = max(1, (int)($get['page']  ?? 1));
        $self->limit      = max(1, min(200, (int)($get['limit'] ?? 50)));
        $self->offset     = ($self->page - 1) * $self->limit;
        $self->wantFacets = ((int)($get['facets'] ?? 0)) === 1;

        $self->csrfToken  = is_string($get['csrf_token'] ?? null) ? (string)$get['csrf_token'] : '';

        // Pass the full GET array through; build_panel_encuesta_filters handles all sanitization
        $self->src        = $get;
        $self->buildOpts  = $buildOpts;

        return $self;
    }
}
