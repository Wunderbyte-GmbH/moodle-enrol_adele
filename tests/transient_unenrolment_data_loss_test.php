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

/**
 * A transient unenrolment (cohort resync, accidental removal + immediate re-add)
 * must not destroy the student's learning-path progress.
 *
 * enrol_adele's user_enrolment_deleted observer HARD-DELETES the user's
 * local_adele_path_user rows the moment their last "carrying" enrolment
 * disappears. The row holds the entire path history: node progress, manual
 * master overrides, first_enrolled stamps for timed windows. A membership blip
 * of seconds - one cohort resync cycle - therefore wipes it irrecoverably; on
 * re-enrolment the student restarts with a fresh, empty snapshot.
 *
 * These tests plant exactly that blip and assert the record survives it
 * (archive-and-reactivate semantics). They FAIL on current code and must pass
 * once the observer archives instead of deleting AND the re-subscription path
 * reactivates the archived row (NB: simply archiving is not enough - the
 * status-agnostic unique index (user_id, learning_path_id) makes the later
 * re-insert collide, and the race-recovery only looks up ACTIVE rows).
 *
 * @package     enrol_adele
 * @copyright   2026 Wunderbyte GmbH
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \enrol_adele\observer::user_enrolment_deleted
 */
final class transient_unenrolment_data_loss_test extends \advanced_testcase {
    /**
     * Host + target course, learning path embedded with option 1, student
     * enrolled in the host course through the REAL flow (generator enrolment
     * fires mod_adele's observer, which subscribes via local_adele). Then a
     * progress sentinel is planted in the created snapshot.
     *
     * @return array [lpid, userid, hostcourseid, pathuserid]
     */
    private function plant_subscribed_student_with_progress(): array {
        global $DB;

        $host = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $json = ['tree' => ['nodes' => [[
            'id' => 'dndnode_1',
            'type' => 'courseNode',
            'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $target->id]],
        ]], 'edges' => []]];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Resync-Testpfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => $user->id,
            'json' => json_encode($json),
        ]);
        $adeleid = $DB->insert_record('adele', (object) [
            'course' => $host->id,
            'name' => 'LP-Aktivität',
            'intro' => '',
            'introformat' => 1,
            'learningpathid' => $lpid,
            'participantslist' => '1',
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        \local_adele\enrol_state::sync_host_course_index(
            (int) $adeleid,
            $lpid,
            (int) $host->id,
            '1'
        );

        // Real flow: enrolling into the host course (option 1) fires mod_adele's
        // observer, which subscribes the user and creates the path_user snapshot.
        $this->getDataGenerator()->enrol_user($user->id, $host->id, 'student', 'manual');
        $record = $DB->get_record(
            'local_adele_path_user',
            ['learning_path_id' => $lpid, 'user_id' => $user->id],
            '*',
            MUST_EXIST
        );

        // Plant months of "progress": a top-level sentinel standing in for
        // manual master overrides / first_enrolled stamps / node history.
        $snapshot = json_decode($record->json, true);
        $snapshot['progress_sentinel'] = 'KEEP-ME';
        $DB->set_field('local_adele_path_user', 'json', json_encode($snapshot), ['id' => $record->id]);

        return [$lpid, (int) $user->id, (int) $host->id, (int) $record->id];
    }

    /**
     * Losing the last carrying enrolment must ARCHIVE the path record, not
     * delete it: the snapshot is the only place the student's history exists.
     * Today the observer hard-deletes it.
     *
     * @return void
     */
    public function test_losing_carrying_enrolment_archives_instead_of_deleting(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        if (!class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('local_adele >= 0.4.3 is required.');
        }

        [$lpid, $userid, $hostid] = $this->plant_subscribed_student_with_progress();

        // The resync "out" half: a real unenrolment event.
        $manual = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $hostid]);
        enrol_get_plugin('manual')->unenrol_user($manual, $userid);

        $this->assertTrue(
            $DB->record_exists(
                'local_adele_path_user',
                ['learning_path_id' => $lpid, 'user_id' => $userid]
            ),
            'The path record (the only copy of the student\'s progress, overrides and '
            . 'timed-window stamps) must survive the loss of the carrying enrolment as an '
            . 'archived row; hard-deleting it makes any transient unenrolment destructive.'
        );
    }

    /**
     * A full resync blip (unenrol + immediate re-enrol, as a cohort sync cycle
     * produces) must hand the student their OWN snapshot back: same record,
     * sentinel intact. Today they get a fresh empty snapshot - months of
     * progress gone.
     *
     * @return void
     */
    public function test_resync_blip_preserves_progress(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        if (!class_exists('\local_adele\enrol_state')) {
            $this->markTestSkipped('local_adele >= 0.4.3 is required.');
        }

        [$lpid, $userid, $hostid, $originalid] = $this->plant_subscribed_student_with_progress();

        // The blip: out ...
        $manual = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $hostid]);
        enrol_get_plugin('manual')->unenrol_user($manual, $userid);
        // ... and right back in (fires mod_adele's observer, which re-subscribes).
        $this->getDataGenerator()->enrol_user($userid, $hostid, 'student', 'manual');

        $active = $DB->get_record(
            'local_adele_path_user',
            ['learning_path_id' => $lpid, 'user_id' => $userid, 'status' => 'active']
        );
        $this->assertNotFalse($active, 'The student must be subscribed again after the resync.');
        $this->assertSame(
            $originalid,
            (int) $active->id,
            'Re-subscription must reactivate the student\'s own archived snapshot, '
            . 'not fabricate a fresh one.'
        );
        $snapshot = json_decode($active->json, true);
        $this->assertSame(
            'KEEP-ME',
            $snapshot['progress_sentinel'] ?? null,
            'The progress sentinel must survive the resync blip; its loss proves the '
            . 'snapshot was destroyed and rebuilt empty.'
        );
    }
}
