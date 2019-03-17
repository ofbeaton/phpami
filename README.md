# phpami
Asterisk Manager Interface (AMI) for PHP 5.4+ supporting legacy v1.4 to v13 with no thrills.

This is a fork and update of the AMI portion of the now defunc [PHPAGI](http://phpagi.sourceforge.net/) PHP 4.4 project which has not been updated since 2010.

We have kept the simplicity of a single class with command methods, adding a packagist entry and namespaces. This is a breaking change as class and method names have changed to follow modern standards, however they are a 1:1 mapping so updating your code should be straight forward.

## Alternatives

If you are dealing with only Asterisk v13+ servers, consider using [PAMI](http://marcelog.github.io/PAMI/) instead, which uses modern design paterns with observer-listener pattern. It no longer supports legacy Asterisk versions like v1.4 however. It may also see less development than the [NAMI](http://marcelog.github.io/Nami/) library for NodeJS.    

## Updates

The project is considered in a usable state and feature complete.

This project is used in corporate applications. As such, the authors are unlikely to update it on a regular basis, but instead when the corporate applications that use it run into problems. You should expect updates in the 5-10yr range. 

Issues and PRs will be monitored, and we will continue to work with the community to provide updates as they are contributed.

## Installing via Composer

The recommended way to install PHPAMI is through
[Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

Next, run the Composer command to install the latest stable version:

```bash
composer.phar require ofbeaton/phpami
```

After installing, you can now use it in your code:

```php
   $ami = new \PHPAMI\Ami();
   if ($ami->connect('localhost:5038', 'myuser', 'mysecret', 'off') === false) {
      throw new \RuntimeException('Could not connect to Asterisk Management Interface.');
   }
   
   // $result contains the output from the command
   $result = $ami->command('core show channels');
   
   $ami->disconnect();
```

## License

This software is distributed under the LGPL 2.1 License. Please see [License file](LICENSE) for more information.

This library is a fork of the now defunc [PHPAGI](http://phpagi.sourceforge.net/) project by Matthew Asham. See [Fork release](https://github.com/ofbeaton/phpami/releases/tag/0.1) for more details.
