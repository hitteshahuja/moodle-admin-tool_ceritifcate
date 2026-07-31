<?php
// This file is part of the tool_certificate plugin for Moodle - http://moodle.org/
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

namespace tool_certificate;

use advanced_testcase;
use tool_certificate_generator;

/**
 * Tests for functions in observer.php
 *
 * @package     tool_certificate
 * @covers      \tool_certificate_observer
 * @copyright   2020 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_test extends advanced_testcase {
    /**
     * Test setup
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Get certificate generator
     * @return tool_certificate_generator
     */
    protected function get_generator(): tool_certificate_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }

    /**
     * Test issues with courseid are removed when course is deleted.
     *
     * @return void
     */
    public function test_course_deleted(): void {
        global $DB;

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $user1 = $this->getDataGenerator()->create_and_enrol($course1);
        $user2 = $this->getDataGenerator()->create_and_enrol($course1);

        $certificate1 = $this->get_generator()->create_template((object) ['name' => 'Template 01']);
        $certificate2 = $this->get_generator()->create_template((object) ['name' => 'Template 02']);
        // Using dummy component name.
        $certificate1->issue_certificate($user1->id, null, [], 'mod_myawesomecert', $course1->id);
        $certificate2->issue_certificate($user1->id, null, [], 'mod_myawesomecert', $course1->id);
        $certificate2->issue_certificate($user2->id, null, [], 'mod_myawesomecert', $course1->id);

        $certificate1->issue_certificate($user1->id, null, [], 'mod_myawesomecert', $course2->id);

        $this->assertEquals(3, $DB->count_records('tool_certificate_issues', ['courseid' => $course1->id]));
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['courseid' => $course2->id]));

        ob_start();
        delete_course($course1);
        ob_end_clean();

        $this->assertEmpty($DB->count_records('tool_certificate_issues', ['courseid' => $course1->id]));
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['courseid' => $course2->id]));
    }

    /**
     * Test email is sent when certificate is regenerated.
     *
     * @return void
     */
    public function test_certificate_regenerated(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $certificate = $this->get_generator()->create_template((object) ['name' => 'Template 01']);

        // Issue the certificate first. This normally triggers `send_issue_notification` inside `issue_certificate`,
        // so we start the message sink AFTER issuing to only catch the regeneration.
        $issueid = $certificate->issue_certificate($user->id, null, [], 'mod_myawesomecert', $course->id);

        $issue = $DB->get_record('tool_certificate_issues', ['id' => $issueid]);

        // Redirect messages so we can intercept the email instead of sending it.
        $sink = $this->redirectMessages();

        // Trigger the regenerated event.
        $event = \tool_certificate\event\certificate_regenerated::create_from_issue($issue);
        $event->trigger();

        // Fetch captured messages.
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);

        $message = reset($messages);
        $this->assertEquals($user->id, $message->useridto);
        $this->assertEquals(get_string('notificationsubjectcertificateregenerated', 'local_mmt_utilities'), $message->subject);
        $this->assertEquals('certificateissued', $message->eventtype); // Even though it's regenerated, we utilize the standard message event channel.
        $this->assertStringContainsString('Your certificate has been regenerated', $message->fullmessage);

        $sink->close();
    }
}
