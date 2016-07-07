# SilverStripe SES Mailer

After installing the module, add configuration similar to the following
to enable the mailer

```yml
---
Name: AWSConfig
---
Injector:
  Mailer:
    class: SESMailer
    constructor:
      config:
        credentials: 
          key: YourKey
          secret: YourSecret
        region: us-west-2
        version: '2010-12-01'
        signature_version: 'v4'
```

Add the following to your project  \_config.php to enable it

```php
$mailer = Injector::inst()->get('SESMailer');
Email::set_mailer($mailer);
```
