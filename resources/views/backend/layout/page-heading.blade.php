<div class="d-flex flex-wrap flex-stack mb-6">
    <div class="d-flex flex-column">
        <h1 class="fw-bold my-1 fs-2">@yield('page_heading')</h1>

        @hasSection('page_subheading')
            <div class="text-muted fs-6 fw-semibold mt-1">@yield('page_subheading')</div>
        @endif
    </div>

    <div class="d-flex align-items-center gap-2">
        @yield('page_actions')
    </div>
</div>
