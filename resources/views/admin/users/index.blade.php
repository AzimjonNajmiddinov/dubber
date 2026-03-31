@extends('admin.layout')

@section('title', 'Users')

@section('content')
<div class="page-title">Users</div>

<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">

    {{-- Create user form --}}
    <div style="background:#1a1f2e;border:1px solid #2d3748;border-radius:10px;padding:24px;min-width:300px;flex:0 0 320px">
        <div style="font-weight:600;margin-bottom:16px">Create User</div>
        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            <div style="margin-bottom:12px">
                <label style="display:block;color:#a0aec0;font-size:0.85rem;margin-bottom:4px">Name</label>
                <input type="text" name="name" value="{{ old('name') }}" style="width:100%" required>
                @error('name')<div style="color:#fc8181;font-size:0.8rem;margin-top:4px">{{ $message }}</div>@enderror
            </div>
            <div style="margin-bottom:12px">
                <label style="display:block;color:#a0aec0;font-size:0.85rem;margin-bottom:4px">Login (email / username)</label>
                <input type="text" name="email" value="{{ old('email') }}" style="width:100%" required>
                @error('email')<div style="color:#fc8181;font-size:0.8rem;margin-top:4px">{{ $message }}</div>@enderror
            </div>
            <div style="margin-bottom:16px">
                <label style="display:block;color:#a0aec0;font-size:0.85rem;margin-bottom:4px">Password</label>
                <input type="password" name="password" style="width:100%" required>
                @error('password')<div style="color:#fc8181;font-size:0.8rem;margin-top:4px">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Create</button>
        </form>
    </div>

    {{-- User list --}}
    <div style="flex:1;min-width:280px">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Login</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                <tr>
                    <td style="color:#718096">{{ $user->id }}</td>
                    <td>{{ $user->name }}</td>
                    <td style="color:#a0aec0">{{ $user->email }}</td>
                    <td style="color:#718096;font-size:0.8rem">{{ $user->created_at->diffForHumans() }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this user?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Del</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" style="color:#718096;text-align:center;padding:40px">No users.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
