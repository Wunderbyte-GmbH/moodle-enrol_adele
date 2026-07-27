<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace enrol_adele;

use enrol_adele\local\instance_manager;
use enrol_adele\local\reconciler;

/**
 * The nightly safety net must heal HOST-course access, not only target courses.
 *
 * Host-course entitlements (participantslist options 2/3) are granted and revoked
 * ONLY by mod_adele's user_enrolment_created/deleted observers. When such an event
 * is missed (bulk operations with events suppressed, an observer exception, direct
 * DB manipulation), the host access is permanently wrong: reconcile_all() - the
 * advertised "safety net" scheduled task - currently sweeps only KIND_TARGET
 * enrolments and never re-derives host entitlement. These tests plant exactly that
 * drift and assert the sweep heals it. They FAIL on current code (the gap) and
 * must pass once reconcile_all() gains a host pass.
 *
 * @package     enrol_adele
 * @copyright   2026 Wunderbyte GmbH
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \enrol_adele\local\reconciler::reconcile_all
 */
final class reconcile_all_host_sweep_test extends \advanced_testcase {
    /**
     * Build host course + starting-node course, a learning path embedded with
     * participantslist option 2, and a subscribed user manually enrolled in the
     * starting-node course - which fires mod_adele's observer and grants ACTIVE
     * host access through the adele KIND_HOST instance (asserted as precondition).
     *
     * @return array [lpid, userid, hostcourseid, nodecourseid]
     */
    private function plant_granted_host_access(): array {
        global $DB;

        $host = $this->getDataGenerator()->create_course();
        $nodecourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // A learning path whose single node is a STARTING node (option 2 keys on
        // parentCourse containing 'starting_node') mapping to the node course.
        $json = ['tree' => ['nodes' => [[
            'id' => 'dndnode_1',
            'type' => 'courseNode',
            'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $nodecourse->id]],
        ]], 'edges' => []]];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Sweep-Testpfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => $user->id,
            'json' => json_encode($json),
        ]);
        $DB->insert_record('local_adele_path_user', (object) [
            'user_id' => $user->id,
            'course_id' => 0,
            'learning_path_id' => $lpid,
            'status' => 'active',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => $user->id,
            'json' => json_encode($json + ['user_path_relation' => [
                'dndnode_1' => ['feedback' => ['status' => 'accessible']],
            ]]),
        ]);

        // Embed the path in the host course with option 2 (starting-node carriers).
        $adeleid = $DB->insert_record('adele', (object) [
            'course' => $host->id,
            'name' => 'LP-Aktivität',
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $lpid,
            'participantslist' => '2',
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        // The fixture writes {adele} directly, so it must sync local_adele's
        // host-course index itself, exactly like adele_add_instance() does.
        \local_adele\enrol_state::sync_host_course_index(
            (int) $adeleid,
            $lpid,
            (int) $host->id,
            '2'
        );

        // Manual enrolment into the starting-node course fires the real
        // user_enrolment_created event; mod_adele's observer derives option-2
        // entitlement and grants host access via reconcile_host_user().
        $this->getDataGenerator()->enrol_user($user->id, $nodecourse->id, 'student', 'manual');

        $ue = $this->get_host_ue($lpid, (int) $host->id, (int) $user->id);
        $this->assertNotFalse($ue, 'Precondition: the observer must have granted host access.');
        $this->assertEquals(ENROL_USER_ACTIVE, $ue->status, 'Precondition: host access must be active.');

        return [$lpid, (int) $user->id, (int) $host->id, (int) $nodecourse->id];
    }

    /**
     * The user enrolment on the adele KIND_HOST instance of a course, or false.
     *
     * @param int $lpid Learning path id.
     * @param int $hostcourseid Host course id.
     * @param int $userid User id.
     * @return \stdClass|false
     */
    private function get_host_ue(int $lpid, int $hostcourseid, int $userid) {
        global $DB;
        $instance = $DB->get_record('enrol', [
            'enrol' => 'adele',
            'courseid' => $hostcourseid,
            'customint1' => $lpid,
            'customint2' => instance_manager::KIND_HOST,
        ]);
        if (!$instance) {
            return false;
        }
        return $DB->get_record('user_enrolments', [
            'enrolid' => $instance->id,
            'userid' => $userid,
        ]);
    }

    /**
     * MISSED REVOCATION: the user loses their carrying (starting-node) enrolment
     * through a channel that fires no event - here a direct DB delete, the stand-in
     * for bulk ops with events suppressed or a crashed observer. The entitlement is
     * factually gone, but no observer ran. The nightly reconcile_all() sweep must
     * then withdraw the host access; today it leaves the stale ACTIVE enrolment
     * behind forever.
     *
     * @return void
     */
    public function test_sweep_revokes_host_access_after_missed_unenrolment(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        if (!class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('local_adele >= 0.4.3 is required.');
        }

        [$lpid, $userid, $hostid, $nodecourseid] = $this->plant_granted_host_access();

        // Remove the carrying enrolment WITHOUT events: delete the user_enrolments
        // row on the node course's manual instance directly.
        $manual = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $nodecourseid]);
        $DB->delete_records('user_enrolments', ['enrolid' => $manual->id, 'userid' => $userid]);

        reconciler::reconcile_all();

        $ue = $this->get_host_ue($lpid, $hostid, $userid);
        $this->assertTrue(
            $ue === false || (int) $ue->status === ENROL_USER_SUSPENDED,
            'reconcile_all() must withdraw host access once the carrying enrolment is gone; '
            . 'the stale ACTIVE host enrolment proves the sweep never re-derives host entitlement.'
        );
    }

    /**
     * MISSED GRANT / EXTERNAL DRIFT: the host enrolment disappears through a
     * channel the observers never see (direct DB manipulation, partial restore,
     * a missed grant event) while the user is still fully entitled (their
     * starting-node enrolment is intact). The nightly reconcile_all() sweep must
     * restore the host access; today it never re-grants host enrolments at all.
     *
     * @return void
     */
    public function test_sweep_restores_host_access_after_external_drift(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        if (!class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('local_adele >= 0.4.3 is required.');
        }

        [$lpid, $userid, $hostid] = $this->plant_granted_host_access();

        // Wipe the adele host enrolment DB-side - no event, observers blind.
        $instance = $DB->get_record('enrol', [
            'enrol' => 'adele',
            'courseid' => $hostid,
            'customint1' => $lpid,
            'customint2' => instance_manager::KIND_HOST,
        ]);
        $DB->delete_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);

        reconciler::reconcile_all();

        $ue = $this->get_host_ue($lpid, $hostid, $userid);
        $this->assertNotFalse(
            $ue,
            'reconcile_all() must restore host access for a still-entitled user; '
            . 'the missing enrolment proves the sweep covers only KIND_TARGET.'
        );
        $this->assertEquals(ENROL_USER_ACTIVE, (int) $ue->status);
    }
}
