/**
 * Construct a URL that points to the SSO start endpoint and append redirect_to / idp_id.
 *
 * PHP passes window.wpo365.ssoStartUrl which is permalink-aware:
 *   - pretty permalinks  → <site url>/wpo/sso/start
 *   - plain permalinks   → <site url>/?wpo_sso_start=1
 *
 * A legacy fallback derives the pretty URL from window.wpo365.siteUrl for
 * older builds that do not yet pass ssoStartUrl.
 */

function getWpoSsoUrl() {
  window.wpo365 = window.wpo365 || {};
  var wpoSsoStartUrl = window.wpo365.ssoStartUrl;

  if (!wpoSsoStartUrl) {
    // Legacy fallback: derive the pretty-permalink form from siteUrl.
    var wpoSiteUrl = (window.wpo365.siteUrl || '').replace(/\/$/, '');

    if (!wpoSiteUrl) {
      console.error('wpoSsoButton: Unexpected error occurred -> ssoStartUrl and siteUrl are both null or empty.');
    }

    wpoSsoStartUrl = wpoSiteUrl + '/wpo/sso/start';
  }

  var wpoRedirectTo = new URLSearchParams(window.location.search).get('redirect_to') || location.href;
  var separator = wpoSsoStartUrl.indexOf('?') === -1 ? '?' : '&';
  var wpoSsoUrl = wpoSsoStartUrl + separator + 'redirect_to=' + encodeURIComponent(wpoRedirectTo);

  if (!!document.getElementById('selectedTenant')) {
    wpoSsoUrl = wpoSsoUrl + '&idp_id=' + document.getElementById('selectedTenant').value;
  }
  return wpoSsoUrl;
}
