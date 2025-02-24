<h1>XPluginLoader<img src="https://raw.githubusercontent.com/XanderID-MC/XPluginLoader/refs/heads/main/icon.png" height="64" width="64" align="left"></h1><br>
XPluginLoader is a powerful and versatile plugin loader designed for PocketMine-MP servers. It enables seamless organization and efficient loading of plugins by categorizing them into structured folders (e.g., Main, Economy, Games). With its dynamic loading capabilities, XPluginLoader simplifies plugin management, making server maintenance and deployment more streamlined and hassle-free.

# Features

- Category-based Plugin Management:
  Organize plugins into distinct categories for better structure and easier maintenance.
- Multiple Loader Support:
  Supports various plugin packaging formats including PHAR, ZIP, and folder-based plugins.
- Dynamic Loading:
  Automatically scans categories directories for valid plugins and loads them during server startup.
- Dependency Resolution:
  Ensures that plugin dependencies are checked and resolved before a plugin is activated.
- Customizable Configuration:
  Easily configure which loaders to use and define your category paths through a simple configuration file.

# Config

``` YAML

---
# Don't edit this, if you don't know what you're doing
config-version: 1.0

# Loader Options
loader:
  # Load Plugin with a Folder?
  folder: true
  # Load Plugin with a Zip?
  # Note:
  # - Please don't Password the Zip File!
  # - Not recommended, Please Use Phar if you want to Archive Plugin
  # - Set allow_url_include=1 in php.ini
  zip: false

# Add your Category Folder Name Here
# Please be reminded that the Root Category Folder should not have plugin.yml and src folders.
categories:
  - "Main"
...
```

# Screenshot
- My structure Plugins folder
  ![Screenshot-1](https://raw.githubusercontent.com/XanderID-MC/XPluginLoader/refs/heads/main/.assets/Screenshot-1.png)

# Todo List
- Auto Categories:
  Automatically Organize Plugins in Root Folder to Folder Categories ( Poggit Categories ) automatically.
  
# Additional Notes

- If you find bugs or want to give suggestions, please visit [here](https://github.com/XanderID-MC/XPluginLoader/issues)