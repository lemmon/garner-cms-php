This page is published and reachable at `/hidden`, but it carries a freeform
`"nav": false` field. The home template filters it out of the nav with the
collection API (`site.children.reject(...)`) — visibility is the author's call,
not a built-in status.
