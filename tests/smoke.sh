#!/bin/bash
# CP smoke test for Eat. Run from the plugin-testing site root.
set -u
BASE="https://plugin-testing.ddev.site"
JAR=$(mktemp)
CURL="curl -sk -b $JAR -c $JAR"

pass=0; fail=0
ok() { echo "  ✓ $1"; pass=$((pass+1)); }
no() { echo "  ✗ $1 — $2"; fail=$((fail+1)); }

TOKEN=$($CURL "$BASE/admin/login" | grep -o 'csrfTokenValue":"[^"]*' | head -1 | cut -d'"' -f3)
[ -n "$TOKEN" ] && ok "got a CSRF token" || no "CSRF token" "none found"

LOGIN=$($CURL -X POST "$BASE/index.php?p=actions/users/login" -H "Accept: application/json" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "loginName=admin" -d "password=claudepassword")
echo "$LOGIN" | grep -q 'csrfTokenValue' && ok "logged in" || no "login" "$LOGIN"

# The token rotates on login, and the response carries the new one.
TOKEN=$(echo "$LOGIN" | grep -o 'csrfTokenValue":"[^"]*' | head -1 | cut -d'"' -f3)

page() {
  local url="$1"; local needle="$2"; local label="$3"
  local body; body=$($CURL "$BASE$url")
  local code; code=$($CURL -o /dev/null -w '%{http_code}' "$BASE$url")
  if [ "$code" = "200" ] && echo "$body" | grep -q "$needle"; then
    ok "$label"
  else
    no "$label" "HTTP $code, needle '$needle' missing"
  fi
}

page "/admin/eat/feeds" "Product feeds" "feeds index renders"
page "/admin/eat/feeds/new" "Attribute map" "new feed screen renders"
page "/admin/eat/taxonomy" "Taxonomy mapping" "taxonomy screen renders"
page "/admin/eat/runs" "Feed runs" "runs screen renders"
page "/admin/settings/plugins/eat" "Feed directory" "settings screen renders"

SAVE=$($CURL -X POST "$BASE/index.php?p=actions/eat/feeds/save" -H "Accept: application/json" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" \
  -d "name=Smoke feed" -d "handle=eatsmoke" -d "channel=google" -d "format=rss" \
  -d "enabled=1" -d "variantMode=variant" -d "interval=0" \
  -d "mappings[0][attribute]=id" -d "mappings[0][source]=attribute" -d "mappings[0][value]=sku" -d "mappings[0][enabled]=1" \
  -d "mappings[1][attribute]=title" -d "mappings[1][source]=attribute" -d "mappings[1][value]=title" -d "mappings[1][enabled]=1" \
  -d "options[skipIncomplete]=" -d "delivery[mode]=file" -d "filters[statuses][]=live")
echo "$SAVE" | grep -q '"message":"Feed saved."' && ok "saved a feed through the CP" || no "save" "$SAVE"

FEEDID=$(echo "$SAVE" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
[ -n "$FEEDID" ] && page "/admin/eat/feeds/$FEEDID" "Smoke feed" "edit screen renders a saved feed"

SWITCH=$($CURL -X POST "$BASE/index.php?p=actions/eat/feeds/save" -H "Accept: application/json" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "id=$FEEDID" \
  -d "name=Smoke feed" -d "handle=eatsmoke" -d "channel=awin" -d "format=csv" \
  -d "enabled=1" -d "variantMode=variant" -d "interval=0" \
  -d "mappings[0][attribute]=id" -d "mappings[0][source]=attribute" -d "mappings[0][value]=sku" -d "mappings[0][enabled]=1" \
  -d "delivery[mode]=file" -d "filters[statuses][]=live")
echo "$SWITCH" | grep -q '"message":"Feed saved."' && ok "switched the feed to another channel" || no "channel switch" "$SWITCH"
$CURL "$BASE/admin/eat/feeds/$FEEDID" | grep -q "merchant_product_id" \
  && ok "the new channel’s attributes replaced the old ones" || no "channel attributes" "awin attributes missing"

PREVIEW=$($CURL -X POST "$BASE/index.php?p=actions/eat/feeds/preview" -H "Accept: application/json" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "id=$FEEDID" -d "limit=2")
echo "$PREVIEW" | grep -q '"columns"' && ok "preview returns rows" || no "preview" "$PREVIEW"

GEN=$($CURL -X POST "$BASE/index.php?p=actions/eat/feeds/generate" -H "Accept: application/json" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "id=$FEEDID" -d "now=1")
echo "$GEN" | grep -q '"message"' && ok "generate now responds" || no "generate" "$GEN"

FEED=$($CURL -o /dev/null -w '%{http_code}' "$BASE/feeds/eatsmoke.csv")
[ "$FEED" = "200" ] && ok "the generated feed is fetchable" || no "feed URL" "HTTP $FEED"

TAX=$($CURL -X POST "$BASE/index.php?p=actions/eat/taxonomy/save" -H "Accept: application/json" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "channel=google" -d "values[smoke]=Test > Category")
echo "$TAX" | grep -q '"message"' && ok "taxonomy saves" || no "taxonomy save" "$TAX"

DEL=$($CURL -X POST "$BASE/index.php?p=actions/eat/feeds/delete" -H "Accept: application/json" \
  -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "id=$FEEDID")
echo "$DEL" | grep -q '"message":"Feed deleted."' && ok "deleted the feed" || no "delete" "$DEL"

rm -f "$JAR"
echo ""
echo "$pass passed, $fail failed"
[ "$fail" = "0" ]
