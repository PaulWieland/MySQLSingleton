# MySQLSingleton

MySQL Singleton makes a singleton object out of a MySQL record and provides basic CRUD operations.

Usage example

```php
<?php
// Some object ID that represents the primary key of the object in the database
$id = 123;

// Create a singleton instance of the widget object
$myWidget = widget::singleton($id);

// Set the color property to blue. This will fail if "color" is not a field available in the database
$myWidget->setProperty("color","blue");

// Do an INSERT or UPDATE to the database (depends on if object with primary key 123 exists or not
$myWidget->save();

// Create a new instance of the same object class with the same id
// Now SQL statements will be performed, instead the original singleton object will be returned
$myWidget2 = widget::singleton($id);

print $myWidget2->color; // outputs blue

// Change the color to red
$myWidget2->setProperty("color","red");

// Illustrate how singleton works
print $myWidget->color; // outputs red

?>
```
