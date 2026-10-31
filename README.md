# Email Students for Moodle™ (local_emailstudents)

Send one nicely formatted, personalised email to exactly the right learners, using your site's own outgoing mail (SMTP) settings.

By LMS Labs (lms-labs.com), from LMS Hosting Services.

## Requirements

Moodle 4.4, 4.5, 5.0, 5.1, 5.2 and 5.3. Cron must be running, because emails are sent in the background.

## Install

1. Site administration > Plugins > Install plugins, then upload the ZIP. Or unzip it into `local/emailstudents` (`public/local/emailstudents` on Moodle 5.1 and later).
2. Complete the upgrade.
3. Check Site administration > Server > Email > Outgoing mail configuration. Email Students uses these settings; there is nothing extra to configure.

## Where to find it

- **Site level:** Site administration > Users > Email Students.
- **In a course:** More > Email participants. This needs the `local/emailstudents:sendcourse` capability, which editing teachers have by default.

## Recipients

Filters combine with AND between filter types and OR inside one filter. The audience panel shows the live count and a sample of names as you change filters.

**Who:**
- Send to people enrolled in courses, or to every active user.
- Course categories (subcategories optional), courses and groups (searchable).
- Roles: student, teacher, non-editing teacher, and any other course role the admin allows.
- Cohorts.
- Last site login.

**Refine:**
- Course completion: completed, not completed, or completed between two dates.
- Course progress below or at least a percentage.
- Course grade below or at least a percentage.
- Not in the course for a number of days.
- Enrolled in the last 7, 14 or 30 days, or enrolment ending in the next 7 to 60 days.
- Has not submitted one or more chosen assignments.
- Enrolment method.
- Any profile field (core or custom) that is or contains a value.

Suspended, deleted, unconfirmed and no-login accounts are never emailed.

Teachers sending from a course can only reach that course. In separate groups mode, without "access all groups", they only reach their own groups.

## Message

- The full rich text editor, including images. Images are served publicly so they show in Gmail, Outlook and phones.
- Attachments: one file is attached as is and several files are zipped. Or send them as login-protected download links.
- From: no-reply with the sender's name, the support contact, or the sender's own address (if allowed email domains permit it).
- Reply-to: replies go to the sender unless you set another address.
- Starter templates: welcome, assessment due, overdue, we miss you, progress check-in, enrolment ending, results released, completion, timetable change, learner survey, USI reminder and urgent notice. You can also save your own templates and share them.

## Personalisation tags

| Group | Tags |
|---|---|
| Learner | {firstname} {lastname} {fullname} {email} {username} {idnumber} {city} {institution} {department} {groupname} |
| Course | {coursename} {courseurl} {coursestartdate} {courseenddate} {enrolstart} {enrolend} |
| Progress | {progress} {coursegrade} {completiondate} {lastaccess} {daysinactive} |
| Sender and site | {sendername} {senderemail} {sitename} {siteurl} {loginurl} |
| Custom profile fields | {profile_shortname}, for example {profile_employer} |

Course tags use the course the email was sent from. For a site-level email, they use the learner's matching course with the newest enrolment.

## Delivery

- Send now or schedule for later.
- Send me a test, with tags filled in.
- Sends in batches through cron. The batch size and pause between batches are configurable for mail providers with rate limits.
- History of every email, with sent, failed, pending and skipped status per recipient, search, cancel, resume, retry failed and use as template.
- Optional copy to the sender with a delivery summary.

## Settings

Site administration > Users > Email Students > Settings:

- **Sending:** batch size, pause between batches, maximum recipients per email.
- **Defaults:** default From, allowed recipient roles.
- **Attachments:** maximum size and count, default delivery mode.
- **Other:** footer added to every email, history retention.

## Capabilities

| Capability | Default |
|---|---|
| local/emailstudents:sendsite | Manager |
| local/emailstudents:sendcourse | Editing teacher, Manager |
| local/emailstudents:viewallhistory | Manager |
| local/emailstudents:choosefrom | Manager |

## Privacy

Full Privacy API support covers sent emails, delivery records, templates and files.

Moodle™ is a registered trademark of Moodle Pty Ltd. LMS Labs is not affiliated with or endorsed by Moodle Pty Ltd.
