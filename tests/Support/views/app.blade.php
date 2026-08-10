{{-- The host's Inertia root view, stubbed. Testbench has no blade
     templates of its own, and this package cannot ship the host's: it
     references @routes, @vite and the host's own asset pipeline. Route
     tests only need the response to render at all so the Inertia page
     object can be asserted. --}}
<!DOCTYPE html>
<html><head>@inertiaHead</head><body>@inertia</body></html>
