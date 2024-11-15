# -*- mode: ruby -*-
# vi: set ft=ruby :

vms = {
# 'ansible'  => {'memory' => '1024', 'cpus' => 1, 'ip' => '10',  'box' => 'ubuntu/kinetic64'},
'debian'   => {'memory' => '1024', 'cpus' => 1, 'ip' => '101', 'box' => 'debian/bullseye64'},
#'alma'     => {'memory' => '1024', 'cpus' => 1, 'ip' => '102', 'box' => 'almalinux/9'},
#'fedora'   => {'memory' => '1024', 'cpus' => 1, 'ip' => '201', 'box' => 'generic/fedora35'},
#'opensuse' => {'memory' => '1024', 'cpus' => 1, 'ip' => '202', 'box' => 'opensuse/Leap-15.4.x86_64'}
}

Vagrant.configure('2') do |config|

  config.vm.box_check_update = false

  vms.each do |name, conf|

    config.vm.define "#{name}" do |k|
      k.vm.hostname = "#{name}.ansible.local"
      k.vm.network 'private_network', ip: "192.168.56.#{conf['ip']}"
      k.vm.box = conf['box']

      k.vm.provider 'virtualbox' do |vb|
        vb.memory = conf['memory']
        vb.cpus = conf['cpus']
      end

      k.vm.provider 'libvirt' do |lv|
        lv.cpus = conf['cpus']
        lv.memory = conf['memory']
        lv.cputopology :sockets => 1, :cores => conf['cpus'], :threads => 1
      end

    end

  end
end
