@props(['label' => 'Actions'])

{{--
    The actions of one table row.

    The trigger is the three dots in the Actions column; the panel holds the
    actions of that row. Only one panel is open at a time.
--}}
<div class="row-menu" data-row-menu>
    <button type="button" class="row-menu-trigger" data-row-menu-trigger aria-haspopup="true" aria-expanded="false" aria-label="{{ $label }}" title="{{ $label }}">
        <svg aria-hidden="true"><use href="#icon-more-horizontal"></use></svg>
    </button>
    <div class="row-menu-panel" data-row-menu-panel role="menu" hidden>
        {{ $slot }}
    </div>
</div>
