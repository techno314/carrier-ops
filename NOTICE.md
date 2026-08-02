# Third-party notices

## FCMS — Fleet Carrier Management System

<https://github.com/FuelRats/FCMS>

Carrier Ops was written after studying FCMS, and owes it a debt worth stating plainly.

**What was taken:** understanding, not code. FCMS is the clearest available description of how
Frontier's Companion API `/fleetcarrier` response is actually shaped — that `name.vanityName` is
hex-encoded UTF-8, that `finance` carries `coreCost` and `servicesCost` separately, that
`itinerary.completed` holds arrivals with visit durations, that `ships.shipyard_list` is an object
while `market.commodities` is a list, and that entries with the category `NonMarketable` are
padding rather than stock. Its model layer also settled which entities a carrier board needs at all:
carrier, market, cargo, itinerary, modules, ships.

**What was not taken:** any of its source. FCMS is Python on Pyramid and SQLAlchemy; this is PHP
with no framework, no ORM and no shared files. Nothing here is a translation of anything there.

Facts about a JSON response are not copyrightable, and neither is the idea of a website that shows
you your fleet carrier. Copyright covers expression, not function. So this notice is less a legal
obligation than an accurate account of where the knowledge came from. FCMS is BSD-3-Clause, and its
terms are reproduced in full below. This project is under the same licence.

Per clause 3: **The Fuel Rats have not endorsed this software and are not associated with it.** Any
resemblance in purpose is because both read the same game's data; do not read it as approval.

```
BSD 3-Clause License

Copyright (c) 2024, The Fuel Rats

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its
   contributors may be used to endorse or promote products derived from
   this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

## Ardent Insight

<https://github.com/iaincollins/ardent-api> · <https://ardent-insight.com/>

The "Sell where?" lookup queries Ardent's public API for stations importing a commodity. No Ardent
code is included; this is an HTTP client calling a documented, keyless endpoint. Ardent's own source
is AGPL-3.0, copyright Iain Collins — that licence covers their software, which is not distributed
here.

Ardent enforces no rate limits and asks for respectful use. What that means in practice is described
under "Finding somewhere to sell" in the README.

## EDMarketConnector

<https://github.com/EDCD/EDMarketConnector>

The plugin in `edmc-plugin/` is loaded by EDMC and uses its documented plugin entry points
(`plugin_start3`, `journal_entry`, `capi_fleetcarrier` and so on). It contains no EDMC code. EDMC is
GPL-2.0, copyright its contributors; BSD-3-Clause is GPL-compatible, so a GPL host loading this
plugin raises no conflict however strictly the boundary is drawn.

The Companion API data this app can receive is fetched by EDMC under EDMC's own Frontier client
registration, at the user's explicit request, and forwarded by the plugin.

## Elite Dangerous

Elite Dangerous is copyright Frontier Developments plc. This software is not endorsed by, nor
reflects the views or opinions of, Frontier Developments. Journal files are read from the user's own
machine with their consent.
