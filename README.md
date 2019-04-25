# SilverStripe SES Mailer

After installing the module, add configuration similar to the following
to enable the mailer

```yml
---
Name: AWSConfig
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Email\Mailer:
    class: Symbiote\SilverStripeSESMailer\SESMailer
    constructor:
      config:
        credentials: 
          key: YourKey
          secret: YourSecret
        region: us-west-2
        version: '2010-12-01'
        signature_version: 'v4'
```


If your SES account is configured with a single 'from' address having being 
verified, you can set an 'always from' email address which will always be the 
'From:' header, with the 'reply-to:' header set based on the calling code's
'From' variable. Just add

```
Injector:
  Mailer:
    properties:
      alwaysFrom: my@address.com
```

Emails will be sent through the QueuedJobs module if it is installed. You can set the following configuration to bypass this behaviour even if QueuedJobs is installed

```
Injector:
  Mailer:
    properties:
      useQueuedJobs: false
```
