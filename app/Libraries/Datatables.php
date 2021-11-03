<?php

/*
* =============================================
* Datatables for Codeigniter 4
* Version           : 1.0.1
* Created By       : Alif Bintoro <alifbintoro77@gmail.com>
* =============================================
*/

namespace App\Libraries;

class Datatables 
{
    protected $db;
    protected $request;
    protected $builder;
    protected $column;
    protected $columnSearch = [];
    protected $order = [];
    protected $joins = [];
    protected $where = [];
    protected $groupBy = null;
    protected $queryCount;
    protected $lastQuery = [];

    public function __construct(Array $config)
    {
        $this->db = \Config\Database::connect(@$config['db'] ?? 'default');
        $this->builder = $this->db->table($config['table']);
        $this->request = \Config\Services::request();
    }

    protected function balanceChars($str, $open, $close)
    {
        $openCount = substr_count($str, $open);
        $closeCount = substr_count($str, $close);
        $retval = $openCount - $closeCount;
        return $retval;
    }

    protected function explode($delimiter, $str, $open='(', $close=')') 
    {
        $retval = [];
        $hold = [];
        $balance = 0;
        $parts = explode($delimiter, $str);
        foreach ($parts as $part){
            $hold[] = $part;
            $balance += $this->balanceChars($part, $open, $close);
            if ($balance < 1){
                $retval[] = implode($delimiter, $hold);
                $hold = [];
                $balance = 0;
            }
        }

        if (count($hold) > 0)
            $retval[] = implode($delimiter, $hold);

        return $retval;
    }

    public function select($columns)
    {
        $this->column = $columns;
        $columns = $this->explode(',', $columns);
        foreach($columns as $val){
            $search = trim(preg_replace('/(.*)\s+as\s+(\w*)/i', '$1', $val));
            if(strpos($search, "FROM") === FALSE){
                $this->columnSearch[] = $search;
            }
        }

        return $this;
    }

    public function join($table, $fk, $type = NULL)
    {
        $this->joins[] = [$table, $fk, $type];

        return $this;
    }

    public function where($keyCondition, $val = NULL)
    {
        $this->where[] = [$keyCondition, $val, 'and'];

        return $this;
    }

    public function orWhere($keyCondition, $val = NULL)
    {
        $this->where[] = [$keyCondition, $val, 'or'];

        return $this;
    }

    public function whereIn($keyCondition, $val = [])
    {
        $this->where[] = [$keyCondition, $val, 'in'];

        return $this;
    }

    public function orderBy($column, $order = 'ASC')
    {
        if(is_array($column)){
            $this->order = $column;
        }else{
            $this->order[$column] = $order;
        }

        return $this;
    }

    public function groupBy($groupBy)
    {
        $this->groupBy = $groupBy;

        return $this;
    }

    protected function _getDatatablesQuery()
    {
        $searchPost = $this->request->getPost('search');

        if(!empty($this->column))
            $this->builder->select($this->column);

        if(!empty($this->joins))
            foreach($this->joins as $val)
                $this->builder->join($val[0], $val[1], $val[2]);

        if(!empty($this->where)){
            foreach($this->where as $val){
                switch($val[2]){
                    case 'and':
                        $this->builder->where($val[0],$val[1]);
                    break;

                    case 'or':
                        $this->builder->orWhere($val[0], $val[1]);
                    break;

                    case 'in':
                        $this->builder->whereIn($val[0], $val[1]);
                    break;
                }
            }
        }

        if(!empty($this->groupBy))
            $this->builder->groupBy($this->groupBy);

        $tmpQueryCount = preg_replace(sprintf('/%s/', $this->column), 'COUNT(1)', str_replace(['`', '"'], ['', ''], $this->builder->getCompiledSelect(false)));

        if($searchPost['value']){
            $i = 0;
            foreach ($this->columnSearch as $item){
                if($i===0){
                    $this->builder->groupStart();
                    $this->builder->like($item, $searchPost['value']);
                }else{
                    $this->builder->orLike($item, $searchPost['value']);
                }

                if(count($this->columnSearch) - 1 == $i)
                    $this->builder->groupEnd();

                $i++;
            }

            $this->queryCount = preg_replace(sprintf('/%s/', $this->column), '('.$tmpQueryCount.') as total_all, COUNT(1) as total_filtered', str_replace(['`', '"'], ['', ''], $this->builder->getCompiledSelect(false)));
        }else{
            $this->queryCount = "SELECT COUNT(1) total_all, COUNT(1) total_filtered ".substr($tmpQueryCount, 16);
        }

        $requestOrder = $this->request->getPost('order');
        if(isset($requestOrder)){
            $this->builder->orderBy($this->columnSearch[$requestOrder['0']['column']], $requestOrder['0']['dir']);
        }else if(!empty($this->order)){
            foreach($this->order as $key => $val)
                $this->builder->orderBy($key, $val);
        }
    }

    protected function getDatatables()
    {
        $this->_getDatatablesQuery();
        if($this->request->getPost('length') != -1)
            $this->builder->limit($this->request->getPost('length'), $this->request->getPost('start'));
        
        $result = $this->builder->get()->getResultArray();
        $this->lastQuery['main'] = $this->builder->getCompiledSelect(false);

        return $result;
    }

    public function countTotal()
    {
        $result = $this->db->query($this->queryCount)->getRowArray();
        $this->lastQuery['count'] = $this->db->getLastQuery();

        return $result;
    }

    public function getLastQuery()
    {
        return $this->lastQuery;
    }

    public function generate(Bool $raw = false)
    {
        $list = $this->getDatatables();
        $total = $this->countTotal();
        $data = [];
        $no = $this->request->getPost('start',true);

        foreach ($list as $val) {
            $no++;
            $val['no'] = $no;
            $data[] = $val;
        }

        $output = array(
                    'draw' => $this->request->getPost('draw',true),
                    'recordsTotal' => $total['total_all'],
                    'recordsFiltered' => $total['total_filtered'],
                    'data' => $data,
                    csrf_token() => csrf_hash(),
                );

        if($raw){
            return $output;
        }
        else{
            header('Content-Type: application/json');
            echo json_encode($output);
        }
    }

}
