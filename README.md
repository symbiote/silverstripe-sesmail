# SilverStripe SES Mailer

After installing the module, add configuration similar to the following
to enable the mailer

```yml
---
Name: AWSConfig
---
Injector:
  SESMailer:
    constructor:
      config:
        key: EnterKey
        secret: EnterSecret
        region: us-west-2
```

Add the following to your project  \_config.php to enable it

```php
$mailer = Injector::inst()->get('SESMailer');
Email::set_mailer($mailer);
```
