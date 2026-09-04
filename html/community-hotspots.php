<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Hotspot Groups - TaraSec</title>
    <meta name="description" content="How local operators and neighbours can share connectivity and rewards through TaraSec community hotspot groups.">
    <link rel="stylesheet" href="lib/fontawesome/css/all.min.css">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a href="index.php" class="navbar-brand"><img src="img/logo-w.png" alt="TaraSec" width="240" height="60"></a>
        <a class="btn btn-outline-light" href="index.php">Home</a>
    </div>
</nav>

<header class="bg-primary text-white py-5">
    <div class="container py-4">
        <h1 class="display-4 fw-bold">Community hotspot groups</h1>
        <p class="lead col-lg-9">A local hotspot operator can invite neighbours to host additional TaraSec hotspots. This can extend affordable coverage while keeping usage and rewards transparent.</p>
    </div>
</header>

<main>
<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body p-4">
                    <i class="fas fa-user-tie fa-2x text-primary mb-3"></i>
                    <h2 class="h4">Local operator</h2>
                    <p>Organizes the hotspot group, helps recruit and support hosts, and may provide the main hotspot where participants can spend earned access credits.</p>
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body p-4">
                    <i class="fas fa-house-signal fa-2x text-success mb-3"></i>
                    <h2 class="h4">Neighbour host</h2>
                    <p>Voluntarily provides a suitable location, electricity and an Internet connection for an additional hotspot, with limits they can control.</p>
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body p-4">
                    <i class="fas fa-book fa-2x text-warning mb-3"></i>
                    <h2 class="h4">TaraSec</h2>
                    <p>Provides the technical platform for identity, security, metering, roaming and bookkeeping. TaraSec receives 10% of the amount charged for delivered usage.</p>
                </div></div>
            </div>
        </div>
    </div>
</section>

<section class="py-5 bg-light">
    <div class="container">
        <div class="row align-items-start g-5">
            <div class="col-lg-7">
                <h2>They decide how to divide the remaining 90%</h2>
                <p class="lead">TaraSec does not prescribe the commercial arrangement between the local operator and the neighbour hosting the connection.</p>
                <p>The operator and host may agree on a percentage split, a fixed reward for measured usage, all proceeds going to one party, or a non-cash reward such as connectivity at the main hotspot. The agreed rule should be accepted by both parties and recorded per hosted gateway.</p>
                <p>Changes should apply only to future usage. Each ledger entry should retain the gross charge, TaraSec's 10% bookkeeping fee, the distributable 90%, the operator and host portions, the gateway, the measured session and the agreement version used.</p>
            </div>
            <div class="col-lg-5">
                <div class="card border-primary shadow-sm"><div class="card-body p-4">
                    <h3 class="h5">Illustrative example</h3>
                    <dl class="row mb-0">
                        <dt class="col-7">Customer charge</dt><dd class="col-5 text-end">100 credits</dd>
                        <dt class="col-7">TaraSec (10%)</dt><dd class="col-5 text-end">10 credits</dd>
                        <dt class="col-7">Available to divide</dt><dd class="col-5 text-end">90 credits</dd>
                    </dl>
                    <hr>
                    <p class="small mb-0">How the 90 credits are divided is decided by the operator and host, not by TaraSec.</p>
                </div></div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <h2>Transparent and voluntary</h2>
        <ul class="lead">
            <li class="mb-2">The neighbour must explicitly agree before their connection is shared.</li>
            <li class="mb-2">Every hosted gateway is identified and its delivered usage is measured separately.</li>
            <li class="mb-2">Both parties should be able to see the agreement, usage and resulting rewards.</li>
            <li class="mb-2">The host can pause sharing and set bandwidth or data limits.</li>
            <li class="mb-2">Credits for Internet access, cashable earnings and borrowed credit remain separate balances.</li>
            <li>Cash withdrawal may require identity, business, tax or payment verification.</li>
        </ul>
    </div>
</section>

<section class="py-5 bg-warning bg-opacity-10">
    <div class="container">
        <div class="alert alert-warning border-warning mb-0 p-4">
            <h2 class="h4"><i class="fas fa-triangle-exclamation me-2"></i>Important legal and service-provider notice</h2>
            <p><strong>TaraSec provides the technical solution and bookkeeping tools. TaraSec does not determine whether a particular person or business has the permits, registrations, tax status or other legal authority required to operate or earn money from a hotspot.</strong></p>
            <p class="mb-0">TaraSec also does not know whether an Internet provider's contract permits sharing, public access, resale or commercial use of a domestic connection. The hotspot operator and connection host are responsible for checking applicable laws, local requirements and the terms of their Internet service before enabling sharing. Availability of the TaraSec technology is not legal or business advice and does not mean that a proposed arrangement is permitted.</p>
        </div>
    </div>
</section>

<section class="py-5 bg-dark text-white">
    <div class="container text-center">
        <h2>Interested in testing a community hotspot group?</h2>
        <p class="lead">The accounting and agreement model is being developed for practical pilots with operators and willing connection hosts.</p>
        <a class="btn btn-primary btn-lg" href="get-involved.php">Get involved</a>
    </div>
</section>
</main>

<footer class="bg-dark text-white border-top border-secondary py-4">
    <div class="container text-center">&copy; <script>document.write(new Date().getFullYear())</script> Taransvar / TaraSec</div>
</footer>
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
