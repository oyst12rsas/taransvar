# Configuring shared and private demos

TaraSec gateways keep normal LAN-to-NetBird access. Demo selection controls only which
nodes the Android app presents. It does not create a restrictive allow-list.

## Shared defaults

- Demo 1 receiver: Tomato (`100.68.22.33`)
- Demo 2 Node A: Roquefort (`100.68.176.110`)
- Demo 2 Node B: Camembert (`100.68.149.164`)

Demo 2 alternatives are active rows in `demoSshSetup` joined to
`demoSshNodeB`. Existing installations continue to use the first active setup
when no gateway-specific selection exists.

## Select alternatives for one gateway

After database version 91 is installed on the central DB server:

```sql
INSERT INTO gatewayDemoConfiguration
    (gatewayIp,gatewayName,demo1ReceiverIp,demo1ReceiverName,
     demoSshSetupId,demo4RouterId,organisationLabel)
VALUES
    (INET_ATON('100.68.51.247'),'Gouda',
     INET_ATON('100.68.22.33'),'Tomato',
     1,NULL,'')
ON DUPLICATE KEY UPDATE
    gatewayName=VALUES(gatewayName),
    demo1ReceiverIp=VALUES(demo1ReceiverIp),
    demo1ReceiverName=VALUES(demo1ReceiverName),
    demoSshSetupId=VALUES(demoSshSetupId),
    demo4RouterId=VALUES(demo4RouterId),
    organisationLabel=VALUES(organisationLabel);
```

Use the gateway's NetBird IPv4 address as `gatewayIp`. The API identifies the
calling gateway by that address. `demoSshSetupId` selects both Demo 2 nodes.
`demo4RouterId` selects the partner route shown first in Demo 4.

A gateway-local deployment can override Demo 1 and the selected setup through
`/etc/tarasecfw.conf`:

```sh
DEMO1_NODE=100.68.22.33
DEMO1_NODE_NAME=Tomato
DEMO2_SETUP_ID=1
DEMO4_ROUTER_ID=1
```

Central per-gateway configuration takes precedence when a matching row exists.

## Demo 3 university visibility

Install or upgrade the separate Demo 3 schema on the DB server:

```sh
mysql taransvar < misc/demo3_assistance_upgrade.sql
```

A session without a group code is public. A session created with a group code is
returned only when the same code is supplied. Only a SHA-256 hash is stored.
The optional group label is display text and is not an access secret.

## Demo 4 requirements

Demo 4 additionally needs:

1. A `partnerRouter.taggedTrafficRoute` for the public partner destination.
2. A selected `demo4RouterId` when a gateway should prefer one route.
3. Gateway route enforcement that sends only locally tagged participant traffic
   to the NetBird next hop and fails closed when that next hop is unavailable.
4. A state report to `appDemo4.php` after the gateway verifies the Linux policy:

```json
{"router_id":1,"state":"applied","message":"policy rule and route verified"}
```

Allowed states are `configured`, `applied`, and `error`. The Android app
shows these separately. A DB configuration is never presented as proof that a
Linux route is active.

The POST is accepted only from localhost or a registered TaraSec gateway range.
The destination router must already have a non-null tagged route.

## Network policy

The intended topology is broad outbound simulated Internet:

- LAN clients may initiate connections to NetBird computers.
- Established replies may return.
- Unsolicited NetBird connections into the private LAN remain blocked.
- Destination nodes perform SSH movement, honeypot handling, rejection,
  correlation, and subsequent traffic tagging.

Do not use demo selection as a NetBird ACL or firewall allow-list.
