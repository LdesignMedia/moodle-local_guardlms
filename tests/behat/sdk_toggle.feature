@local @local_guardlms
Feature: Real-time monitoring settings tell an admin what is going on
  In order to switch on real-time monitoring without support
  As an administrator
  I need the settings page to state exactly one reason when it is not working

  # These scenarios assert the end-to-end rendering. The copy-edit-proof half of
  # each criterion - that the correct lang-string KEY is selected by the
  # precedence chain - is asserted in tests/sdk_config_test.php against
  # sdk_config::status(), because no core Behat step can assert a string by key.
  # Split deliberately: rewording a string breaks only the scenario below, and
  # the chain itself stays guarded by the unit test.
  #
  # UX8 (Moodle below 4.4) has no scenario here. Detecting it needs $CFG->version
  # moved backwards, which Behat cannot do without breaking the site under test.
  # It is covered by sdk_config_test::test_status_row8_requires44_beats_rows_5_4_7_and_1.
  #
  # The precedence chain is 2 -> 8 -> 5 -> 4 -> 7 -> 1. Rows 3 and 6 are
  # advisories and render alongside whichever headline the chain selected.

  Background:
    Given the following config values are set as admin:
      | apikey         | push-key-from-connect | local_guardlms |
      | connectedat    | 1753660800            | local_guardlms |
      | sdkrefreshedat | 1753660800            | local_guardlms |
    And I log in as "admin"

  Scenario: UX0 Enabling real-time monitoring takes one tick and one save
    Given the following config values are set as admin:
      | sdkkey                | glms_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa | local_guardlms |
      | sdkurl                | https://app.guardlms.com/sdk/guardlms.min.js?v=abc123def456   | local_guardlms |
      | sdkerrorsendpoint     | https://app.guardlms.com/api/sdk/errors/collect               | local_guardlms |
      | sdkbackendenabled     | 1                                                            | local_guardlms |
      | sdksubscriptionactive | 1                                                            | local_guardlms |
      | sdkanalyticsallowed   | 1                                                            | local_guardlms |
      | sdkenabled            | 0                                                            | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "Real-time monitoring"
    And I should see "This site is ready for real-time monitoring. Tick the box below and save to switch it on."
    # Two clicks, and nothing is typed or pasted: the key arrived with the connection.
    When I set the field "Enable real-time monitoring" to "1"
    And I press "Save changes"
    And I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "Real-time monitoring is active. Errors from this site are being reported to GuardLMS."

  Scenario: UX1 A site that has not fetched its key is told to refresh
    Given the following config values are set as admin:
      | sdkrefreshedat | 0 | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "The monitoring key has not been fetched yet. Use Refresh now to fetch it."
    And I should see "Refresh now"
    And I should see "No successful refresh yet."
    And I should not see "1970"

  Scenario: UX2 A GuardLMS that predates the feature hides the section entirely
    # Rows 1 and 7 are also true here; row 2 outranks both and says nothing at all.
    Given the following config values are set as admin:
      | sdkbackendunsupported | 1                  | local_guardlms |
      | sdkrefresherror       | connection refused | local_guardlms |
      | sdkrefreshedat        | 0                  | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should not see "Real-time monitoring"
    And I should not see "connection refused"
    And I should not see "The monitoring key has not been fetched yet. Use Refresh now to fetch it."

  Scenario: UX3 A plan without analytics disables the analytics box and says why
    Given the following config values are set as admin:
      | sdkkey                | glms_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa | local_guardlms |
      | sdkurl                | https://app.guardlms.com/sdk/guardlms.min.js?v=abc123def456   | local_guardlms |
      | sdkbackendenabled     | 1                                                            | local_guardlms |
      | sdksubscriptionactive | 1                                                            | local_guardlms |
      | sdkanalyticsallowed   | 0                                                            | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "Analytics is not included in your GuardLMS plan - error monitoring is still active."
    # An advisory: the headline chosen by the chain still renders.
    And I should see "This site is ready for real-time monitoring. Tick the box below and save to switch it on."
    And the "id_s_local_guardlms_sdkanalytics" "field" should be disabled

  Scenario: UX4 An inactive subscription is stated and outranks rows 7 and 1
    Given the following config values are set as admin:
      | sdkkey                | glms_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa | local_guardlms |
      | sdkbackendenabled     | 1                                                            | local_guardlms |
      | sdksubscriptionactive | 0                                                            | local_guardlms |
      | sdkrefresherror       | connection refused                                           | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "No active GuardLMS subscription - real-time data is not being collected."
    And I should not see "connection refused"

  Scenario: UX5 The dashboard master switch outranks rows 4, 7 and 1
    Given the following config values are set as admin:
      | sdkbackendenabled     | 0                  | local_guardlms |
      | sdksubscriptionactive | 0                  | local_guardlms |
      | sdkrefresherror       | connection refused | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "Real-time monitoring is turned off in the GuardLMS dashboard."
    And I should not see "No active GuardLMS subscription - real-time data is not being collected."
    And I should not see "connection refused"
    And I should not see "The monitoring key has not been fetched yet. Use Refresh now to fetch it."

  Scenario: UX6 A domain mismatch names both hosts as an advisory
    Given the following config values are set as admin:
      | sdkkey                 | glms_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa | local_guardlms |
      | sdkurl                 | https://app.guardlms.com/sdk/guardlms.min.js?v=abc123def456   | local_guardlms |
      | sdkbackendenabled      | 1                                                            | local_guardlms |
      | sdksubscriptionactive  | 1                                                            | local_guardlms |
      | sdkanalyticsallowed    | 1                                                            | local_guardlms |
      | sdkalloweddomains      | ["example.com"]                                              | local_guardlms |
      | sdkalloweddomainsmatch | 0                                                            | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "GuardLMS only accepts data from example.com"
    And I should see "Update Allowed domains in the GuardLMS dashboard."
    And I should see "This site is ready for real-time monitoring. Tick the box below and save to switch it on."

  Scenario: UX7 A failed refresh shows the reason, a retry, and a real last-success line
    # Rows 7 and 1 are both true; row 7 wins, and the date must never be an epoch.
    Given the following config values are set as admin:
      | sdkrefreshedat  | 0                                       | local_guardlms |
      | sdkrefresherror | Could not resolve host app.guardlms.com | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "Could not resolve host app.guardlms.com"
    And I should see "Refresh now"
    And I should see "No successful refresh yet."
    And I should not see "1970"
    And I should not see "The monitoring key has not been fetched yet. Use Refresh now to fetch it."

  Scenario: UX7 A site that has refreshed before shows when that was
    Given the following config values are set as admin:
      | sdkrefresherror | Could not resolve host app.guardlms.com | local_guardlms |
    When I navigate to "Plugins > Local plugins > GuardLMS" in site administration
    Then I should see "Could not resolve host app.guardlms.com"
    And I should see "Last successful refresh:"
    And I should not see "No successful refresh yet."

  Scenario: E7 Refresh now is refused without a sesskey
    When I visit "/local/guardlms/sdkrefresh.php"
    Then I should see "A required parameter (sesskey) was missing"

  Scenario: E7 Refresh now is refused to a user without site configuration
    Given I log out
    And the following "users" exist:
      | username |
      | teacher1 |
    And I log in as "teacher1"
    # require_capability runs before require_sesskey, so this fails on the
    # capability rather than leaking whether the endpoint exists.
    When I visit "/local/guardlms/sdkrefresh.php"
    Then I should see "Sorry, but you do not currently have permissions to do that"
